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
            new TwigFunction('get_bons_plans_products', [$this, 'getBonsPlansProducts']),
            new TwigFunction('get_products_by_category', [$this, 'getProductsByCategory']),
            new TwigFunction('get_products_by_codes', [$this, 'getProductsByCodes']),
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

    /** @return array<ProductInterface> */
    public function getBonsPlansProducts(): array
    {
        // Slugs as they actually exist in the database (with reference codes appended)
        $slugs = [
            'oreiller-plume-d-oie-x2-50050191',
            'buffet-cuisine-divino-80-cm-noir-bois-30150307',
            'tente-de-camping-pop-up-2p-3323218',
        ];

        // Name fragments to search as fallback for products not found by slug
        $nameFragments = [
            'Balai autonettoyant',
        ];

        $products = [];

        // Fetch by slug
        foreach ($slugs as $slug) {
            $qb = $this->productRepository->createQueryBuilder('o');
            $qb->leftJoin('o.translations', 't')
               ->where('t.slug = :slug')
               ->setParameter('slug', $slug)
               ->setMaxResults(1);

            $product = $qb->getQuery()->getOneOrNullResult();

            if ($product instanceof ProductInterface) {
                $products[] = $product;
            }
        }

        // Fetch by name fragment fallback
        foreach ($nameFragments as $name) {
            $qb = $this->productRepository->createQueryBuilder('o');
            $qb->leftJoin('o.translations', 't')
               ->where('t.name LIKE :name')
               ->setParameter('name', '%' . $name . '%')
               ->setMaxResults(1);

            $product = $qb->getQuery()->getOneOrNullResult();

            if ($product instanceof ProductInterface) {
                $products[] = $product;
            }
        }

        // If still not enough, fill with latest products
        if (count($products) < 4) {
            $existingIds = array_map(fn($p) => $p->getId(), $products);
            $qb = $this->productRepository->createQueryBuilder('o');
            $qb->innerJoin('o.variants', 'v')
               ->andWhere('o.enabled = :enabled')
               ->andWhere('v.enabled = :enabled')
               ->orderBy('o.createdAt', 'DESC')
               ->setParameter('enabled', true)
               ->setMaxResults(4);

            if (!empty($existingIds)) {
                $qb->andWhere('o.id NOT IN (:ids)')
                   ->setParameter('ids', $existingIds);
            }

            $extras = $qb->getQuery()->getResult();
            foreach ($extras as $extra) {
                if (count($products) >= 4) break;
                $products[] = $extra;
            }
        }

        return array_slice($products, 0, 4);
    }

    /** @return array<ProductInterface> */
    public function getProductsByCategory(string $taxonSlug, int $limit = 3): array
    {
        $qb = $this->productRepository->createQueryBuilder('o');
        $qb->select('DISTINCT o')
           ->innerJoin('o.variants', 'v')
           ->innerJoin('o.productTaxons', 'pt')
           ->innerJoin('pt.taxon', 't')
           ->andWhere('o.enabled = :enabled')
           ->andWhere('v.enabled = :enabled')
           ->andWhere('t.code = :taxonCode')
           ->orderBy('o.createdAt', 'DESC')
           ->setParameter('enabled', true)
           ->setParameter('taxonCode', $taxonSlug)
           ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /** @return array<ProductInterface> */
    public function getProductsByCodes(array $codes): array
    {
        if (empty($codes)) {
            return [];
        }

        $qb = $this->productRepository->createQueryBuilder('o');
        $qb->andWhere('o.code IN (:codes)')
           ->andWhere('o.enabled = :enabled')
           ->setParameter('codes', $codes)
           ->setParameter('enabled', true);

        $results = $qb->getQuery()->getResult();

        // Preserve the requested order
        $indexed = [];
        foreach ($results as $product) {
            $indexed[$product->getCode()] = $product;
        }

        $ordered = [];
        foreach ($codes as $code) {
            if (isset($indexed[$code])) {
                $ordered[] = $indexed[$code];
            }
        }

        return $ordered;
    }
}
