<?php

namespace App\Twig;

use Sylius\Component\Product\Repository\ProductRepositoryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ProductsExtension extends AbstractExtension
{
    public function __construct(
        private ProductRepositoryInterface $productRepository
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_latest_products', [$this, 'getLatestProducts']),
        ];
    }

    public function getLatestProducts(int $limit = 8): array
    {
        return $this->productRepository->findBy([], ['createdAt' => 'desc'], $limit);
    }
}
