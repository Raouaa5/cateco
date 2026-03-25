<?php

declare(strict_types=1);

namespace App\Repository;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductRepository as BaseProductRepository;

class ProductRepository extends \Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductRepository
{
    public function createShopListQueryBuilder(
        \Sylius\Component\Core\Model\ChannelInterface $channel,
        \Sylius\Component\Core\Model\TaxonInterface $taxon,
        string $locale,
        array $sorting = [],
        bool $includeAllDescendants = false
    ): \Doctrine\ORM\QueryBuilder {
        
        // Handle Nouveauté special category
        if ($taxon->getCode() === 'nouveaute') {
            $queryBuilder = $this->createQueryBuilder('o')
                ->addSelect('translation')
                ->addSelect('productTaxon')
                ->innerJoin('o.translations', 'translation', 'WITH', 'translation.locale = :locale')
                ->leftJoin('o.productTaxons', 'productTaxon')   // required alias for grid sorter
                ->andWhere(':channel MEMBER OF o.channels')
                ->andWhere('o.enabled = :enabled')
                ->setParameter('locale', $locale)
                ->setParameter('channel', $channel)
                ->setParameter('enabled', true)
            ;

            // Apply default sorting by newest if no specific sorting is requested
            if (empty($sorting)) {
                $queryBuilder->addOrderBy('o.createdAt', 'DESC');
            }

            // Grid hack for price sorting (needed to not break frontend price sorting)
            if (isset($sorting['price'])) {
                $subQuery = $this->createQueryBuilder('m')
                     ->select('min(v.position)')
                     ->innerJoin('m.variants', 'v')
                     ->andWhere('m.id = :product_id')
                     ->andWhere('v.enabled = :enabled')
                ;

                $queryBuilder
                    ->addSelect('variant')
                    ->addSelect('channelPricing')
                    ->innerJoin('o.variants', 'variant')
                    ->innerJoin('variant.channelPricings', 'channelPricing')
                    ->andWhere('channelPricing.channelCode = :channelCode')
                    ->andWhere(
                        $queryBuilder->expr()->in(
                            'variant.position',
                            str_replace(':product_id', 'o.id', $subQuery->getDQL()),
                        ),
                    )
                    ->setParameter('channelCode', $channel->getCode())
                    ->setParameter('enabled', true)
                ;
            }
        } else {
            // Normal fallback for regular categories
            $queryBuilder = parent::createShopListQueryBuilder($channel, $taxon, $locale, $sorting, $includeAllDescendants);
        }

        // Prepend our images-first sort exactly as before
        $orderByParts = $queryBuilder->getDQLPart('orderBy');
        $queryBuilder->resetDQLPart('orderBy');
        
        // Use SIZE() to sort by image count. Higher count first.
        $queryBuilder->addOrderBy('SIZE(o.images)', 'DESC');
        
        // Re-append existing sorts as secondary rules
        foreach ($orderByParts as $orderBy) {
            foreach ($orderBy->getParts() as $part) {
                $sortPart = (string) $part;
                $bits = explode(' ', trim($sortPart));
                $field = $bits[0];
                $direction = isset($bits[1]) ? strtoupper($bits[1]) : 'ASC';
                
                $queryBuilder->addOrderBy($field, $direction);
            }
        }

        return $queryBuilder;
    }

    public function findByPhrase(string $phrase, string $locale, ?int $limit = null, ?\Sylius\Component\Core\Model\ChannelInterface $channel = null): iterable
    {
        $queryBuilder = $this->createQueryBuilder('o')
            ->addSelect('translation')
            ->innerJoin('o.translations', 'translation', 'WITH', 'translation.locale = :locale')
            ->andWhere('o.enabled = :enabled')
            ->setParameter('locale', $locale)
            ->setParameter('enabled', true)
        ;

        if (null !== $channel) {
            $queryBuilder
                ->andWhere(':channel MEMBER OF o.channels')
                ->setParameter('channel', $channel)
            ;
            
            // Join variant and channel pricing to ensure prices are available in the card
            $queryBuilder
                ->addSelect('variant')
                ->addSelect('channelPricing')
                ->innerJoin('o.variants', 'variant')
                ->innerJoin('variant.channelPricings', 'channelPricing')
                ->andWhere('channelPricing.channelCode = :channelCode')
                ->setParameter('channelCode', $channel->getCode())
            ;
        }

        $words = explode(' ', str_replace(['+', '-', '*', '~', '@'], ' ', $phrase));
        $i = 0;
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) < 2) {
                continue;
            }

            $parameterName = 'word' . $i;
            $queryBuilder
                ->andWhere($queryBuilder->expr()->orX(
                    'translation.name LIKE :' . $parameterName,
                    'translation.description LIKE :' . $parameterName,
                    'o.code LIKE :' . $parameterName
                ))
                ->setParameter($parameterName, '%' . $word . '%')
            ;
            $i++;
        }

        // Prioritize products with images, then by creation date
        $queryBuilder
            ->addOrderBy('SIZE(o.images)', 'DESC')
            ->addOrderBy('o.createdAt', 'DESC')
        ;

        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
