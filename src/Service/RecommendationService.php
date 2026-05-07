<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches personalized product recommendations from the CATECO V4 FastAPI service
 * and hydrates them into Sylius Product entities.
 *
 * - Results are cached per user to avoid repeated API calls on the same page.
 * - On any error (API down, timeout, etc.) the service returns [] silently
 *   so that the recommendation blocks simply don't appear — no page crash.
 */
final class RecommendationService
{
    /** Docker-internal URL of the FastAPI recommender service */
    private const API_BASE = 'http://recommender:8000';

    /** Cache TTL: 1 hour for known users, 6 hours for fallback (anonymous) */
    private const CACHE_TTL_KNOWN   = 3600;
    private const CACHE_TTL_FALLBACK = 21600;

    /** API timeout — never block the page for more than this */
    private const API_TIMEOUT = 1.5;

    public function __construct(
        private readonly HttpClientInterface        $httpClient,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CacheInterface             $cache,
        private readonly LoggerInterface            $logger,
    ) {}

    /**
     * Returns an array of Sylius Product entities recommended for $userId.
     *
     * @param int   $userId          Sylius customer ID (0 = anonymous → fallback)
     * @param int   $topK            Number of recommendations to return
     * @param int[] $excludeIds      Product IDs to exclude (current product, cart items…)
     *
     * @return ProductInterface[]
     */
    public function getRecommendations(int $userId, int $topK = 6, array $excludeIds = []): array
    {
        $cacheKey = sprintf('cateco_recs_u%d_k%d', $userId, $topK);

        try {
            /** @var array<array{product_id: int, score: float}> $recs */
            $recs = $this->cache->get($cacheKey, function (ItemInterface $item) use ($userId, $topK): array {
                $data = $this->callApi($userId, $topK);
                // Cache longer for the anonymous fallback (popular products change slowly)
                $item->expiresAfter($data['fallback'] ? self::CACHE_TTL_FALLBACK : self::CACHE_TTL_KNOWN);
                
                // User requested to completely hide recommendations if they are not truly personalized
                if ($data['fallback'] ?? false) {
                    return [];
                }
                
                return $data['recommendations'] ?? [];
            });
        } catch (\Throwable $e) {
            $this->logger->warning('RecommendationService: cache/API error — {msg}', ['msg' => $e->getMessage()]);
            return [];
        }

        // Extract product IDs, applying exclusion list
        $productIds = array_values(array_filter(
            array_column($recs, 'product_id'),
            fn(int $id) => !in_array($id, $excludeIds, true)
        ));

        if (empty($productIds)) {
            return [];
        }

        // Hydrate Sylius Product entities
        return $this->loadProducts($productIds);
    }

    /**
     * Convenience method: returns recommendations with full API response data
     * (score, svd_score, etc.) alongside the Product entity.
     *
     * Useful when you want to display the score or use it for debug.
     *
     * @return array<array{product: ProductInterface, score: float, svd_score: float}>
     */
    public function getRecommendationsWithScores(int $userId, int $topK = 6, array $excludeIds = []): array
    {
        $cacheKey = sprintf('cateco_recs_scored_u%d_k%d', $userId, $topK);

        try {
            $recs = $this->cache->get($cacheKey, function (ItemInterface $item) use ($userId, $topK): array {
                $data = $this->callApi($userId, $topK);
                $item->expiresAfter($data['fallback'] ? self::CACHE_TTL_FALLBACK : self::CACHE_TTL_KNOWN);
                
                if ($data['fallback'] ?? false) {
                    return [];
                }
                
                return $data['recommendations'] ?? [];
            });
        } catch (\Throwable $e) {
            $this->logger->warning('RecommendationService: {msg}', ['msg' => $e->getMessage()]);
            return [];
        }

        $filtered = array_filter($recs, fn($r) => !in_array($r['product_id'], $excludeIds, true));
        if (empty($filtered)) {
            return [];
        }

        $products = $this->loadProducts(array_column($filtered, 'product_id'));
        $byId     = [];
        foreach ($products as $p) {
            $byId[$p->getId()] = $p;
        }

        $result = [];
        foreach ($filtered as $rec) {
            $pid = $rec['product_id'];
            if (isset($byId[$pid])) {
                $result[] = [
                    'product'   => $byId[$pid],
                    'score'     => $rec['score']     ?? 0.0,
                    'svd_score' => $rec['svd_score'] ?? 0.0,
                    'fallback'  => $rec['svd_score'] === null,
                ];
            }
        }

        return array_slice($result, 0, count($filtered));
    }

    // ─────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────

    /** @return array{fallback: bool, recommendations: array<array{product_id: int, score: float|null}>} */
    private function callApi(int $userId, int $topK): array
    {
        try {
            $response = $this->httpClient->request('GET', self::API_BASE . '/recommendations', [
                'query'   => ['user_id' => $userId, 'top_k' => $topK],
                'timeout' => self::API_TIMEOUT,
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('RecommendationService: API returned HTTP {code}', [
                    'code' => $response->getStatusCode(),
                ]);
                return ['fallback' => true, 'recommendations' => []];
            }

            return $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('RecommendationService: API unreachable — {msg}', ['msg' => $e->getMessage()]);
            return ['fallback' => true, 'recommendations' => []];
        }
    }

    /**
     * Load Sylius Product entities in bulk, preserving API ranking order.
     *
     * @param int[] $productIds
     * @return ProductInterface[]
     */
    private function loadProducts(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        /** @var ProductInterface[] $products */
        $products = $this->productRepository->findBy(['id' => $productIds]);

        // Preserve the ranking order from the API
        $byId = [];
        foreach ($products as $p) {
            $byId[$p->getId()] = $p;
        }

        $ordered = [];
        foreach ($productIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }
}
