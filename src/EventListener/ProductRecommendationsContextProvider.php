<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\RecommendationService;
use Sylius\Bundle\UiBundle\ContextProvider\ContextProviderInterface;
use Sylius\Bundle\UiBundle\Registry\TemplateBlock;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Injects personalized recommendations into the Sylius product page
 * via the Twig Hook system (SyliusUiBundle).
 *
 * Hooked into: sylius.shop.product.show.content (after the main product block)
 */
final class ProductRecommendationsContextProvider implements ContextProviderInterface
{
    public function __construct(
        private readonly RecommendationService $recommendationService,
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    public function provide(array $templateContext, TemplateBlock $templateBlock): array
    {
        $product = $templateContext['product'] ?? null;
        if (!$product instanceof ProductInterface) {
            return $templateContext;
        }

        // Exclude the current product from recommendations
        $excludeIds = [(int) $product->getId()];

        $userId          = $this->resolveUserId();
        $recommendations = $this->recommendationService->getRecommendations(
            userId:     $userId,
            topK:       6,
            excludeIds: $excludeIds,
        );

        return array_merge($templateContext, [
            'recommendations' => $recommendations,
        ]);
    }

    public function supports(TemplateBlock $templateBlock): bool
    {
        // Only activate on the product show page hook
        return $templateBlock->getEventName() === 'sylius.shop.product.show.content';
    }

    private function resolveUserId(): int
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return 0;
        }

        $user = $token->getUser();
        if (!$user instanceof CustomerInterface) {
            return 0;
        }

        return (int) $user->getId();
    }
}
