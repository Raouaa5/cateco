<?php

declare(strict_types=1);

namespace App\Twig;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Provides is_in_wishlist(productId) for templates to pre-fill heart state.
 * Uses raw DBAL to avoid Doctrine ORM entity mapping complexities.
 */
final class WishlistExtension extends AbstractExtension
{
    /** @var int[]|null */
    private ?array $cachedIds = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_in_wishlist', $this->isInWishlist(...)),
        ];
    }

    public function isInWishlist(int $productId): bool
    {
        $user = $this->security->getUser();
        if (!$user || !method_exists($user, 'getCustomer')) {
            return false;
        }

        if ($this->cachedIds === null) {
            try {
                $customer = $user->getCustomer();
                if (!$customer) {
                    $this->cachedIds = [];
                } else {
                    $rows = $this->connection->fetchAllAssociative(
                        'SELECT product_id FROM cateco_wishlist_item WHERE customer_id = :cid',
                        ['cid' => $customer->getId()]
                    );
                    $this->cachedIds = array_column($rows, 'product_id');
                    // Cast to int for strict comparison
                    $this->cachedIds = array_map('intval', $this->cachedIds);
                }
            } catch (\Exception $e) {
                $this->cachedIds = [];
            }
        }

        return in_array($productId, $this->cachedIds, true);
    }
}
