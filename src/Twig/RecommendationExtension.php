<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\RecommendationService;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig Extension that exposes recommendation functions directly in templates.
 *
 * Usage in any Twig template:
 *   {% set recs = cateco_recommendations(6, [product.id]) %}
 *   {% include '@SyliusShop/shared/_recommendations.html.twig' with {recommendations: recs} %}
 */
final class RecommendationExtension extends AbstractExtension
{
    public function __construct(
        private readonly RecommendationService $recommendationService,
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cateco_recommendations', [$this, 'getRecommendations']),
            new TwigFunction('cateco_user_id',         [$this, 'getCurrentUserId']),
        ];
    }

    /**
     * @param int   $topK       Number of recommendations to return
     * @param int[] $excludeIds Product IDs to exclude (current product, cart items…)
     *
     * @return ProductInterface[]
     */
    public function getRecommendations(int $topK = 6, array $excludeIds = []): array
    {
        return $this->recommendationService->getRecommendations(
            userId:     $this->getCurrentUserId(),
            topK:       $topK,
            excludeIds: $excludeIds,
        );
    }

    public function getCurrentUserId(): int
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return 0;
        }

        $user = $token->getUser();
        if ($user instanceof \Sylius\Component\Core\Model\ShopUserInterface && $user->getCustomer() !== null) {
            return (int) $user->getCustomer()->getId();
        }

        return 0;
    }
}
