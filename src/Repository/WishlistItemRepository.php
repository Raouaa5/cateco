<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Wishlist\WishlistItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ProductInterface;

/**
 * @extends ServiceEntityRepository<WishlistItem>
 */
class WishlistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WishlistItem::class);
    }

    /**
     * @return WishlistItem[]
     */
    public function findByCustomer(CustomerInterface $customer): array
    {
        try {
            return $this->createQueryBuilder('w')
                ->andWhere('w.customer = :customer')
                ->setParameter('customer', $customer)
                ->orderBy('w.createdAt', 'DESC')
                ->getQuery()
                ->getResult();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function findOneByCustomerAndProduct(
        CustomerInterface $customer,
        ProductInterface $product
    ): ?WishlistItem {
        try {
            return $this->createQueryBuilder('w')
                ->andWhere('w.customer = :customer')
                ->andWhere('w.product = :product')
                ->setParameter('customer', $customer)
                ->setParameter('product', $product)
                ->getQuery()
                ->getOneOrNullResult();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Return an array of product IDs that a customer has wishlisted.
     * Used by Twig extension for initial heart state.
     *
     * @return int[]
     */
    public function findProductIdsByCustomer(CustomerInterface $customer): array
    {
        $rows = $this->createQueryBuilder('w')
            ->select('IDENTITY(w.product) AS pid')
            ->andWhere('w.customer = :customer')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'pid');
    }
}
