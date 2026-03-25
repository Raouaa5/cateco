<?php

namespace App\Twig;

use App\Repository\ProductRepository;
use Sylius\Component\Core\Model\ProductInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ProductsExtension extends AbstractExtension
{
    public function __construct(
        private ProductRepository $productRepository
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_latest_products', [$this, 'getLatestProducts']),
            new TwigFunction('get_banner_products', [$this, 'getBannerProducts']),
        ];
    }

    /** @return array<ProductInterface> */
    public function getLatestProducts(int $limit = 8): array
    {
        $qb = $this->productRepository->createQueryBuilder('o');
        $qb->innerJoin('o.variants', 'v')
           ->andWhere('o.enabled = :enabled')
           ->andWhere('v.enabled = :enabled')
           ->andWhere('v.onHand > 0 OR v.tracked = :false')
           ->orderBy('o.createdAt', 'DESC')
           ->setParameter('enabled', true)
           ->setParameter('false', false)
           ->setMaxResults($limit);

        /** @var array<ProductInterface> $result */
        $result = $qb->getQuery()->getResult();
        return $result;
    }

    /** @return array<ProductInterface> */
    public function getBannerProducts(): array
    {
        $slugs = [
            'paillasson-caillebotis-60x40-cm-74431',
            's-choir-infinity-30-73816',
            'balai-magique-seau-essoreur-double-9694',
            'r-chaud-fonte-3-robinets-70173',
            'b-che-industrielle-70476'
        ];

        $products = [];
        foreach ($slugs as $slug) {
            $qb = $this->productRepository->createQueryBuilder('o');
            $qb->leftJoin('o.translations', 't')
               ->where('t.slug = :slug')
               ->setParameter('slug', $slug)
               ->setMaxResults(1);
            
            $product = $qb->getQuery()->getOneOrNullResult();
            
            if (!$product) {
                $product = $this->productRepository->findOneBy(['code' => $slug]);
            }

            if ($product instanceof ProductInterface) {
                $products[] = $product;
            }
        }

        return $products;
    }
}
