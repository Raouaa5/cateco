<?php

declare(strict_types=1);

namespace App\Repository;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Doctrine\ORM\Query\Expr\OrderBy;

/** @phpstan-ignore missingType.generics */
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
                ->leftJoin('o.productTaxons', 'productTaxon')
                ->andWhere(':channel MEMBER OF o.channels')
                ->andWhere('o.enabled = :enabled')
                ->setParameter('locale', $locale)
                ->setParameter('channel', $channel)
                ->setParameter('enabled', true)
            ;

            if (empty($sorting)) {
                $queryBuilder->addOrderBy('o.createdAt', 'DESC');
            }

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
            $queryBuilder = parent::createShopListQueryBuilder($channel, $taxon, $locale, $sorting, $includeAllDescendants);
        }

        /** @var OrderBy[] $orderByParts */
        $orderByParts = $queryBuilder->getDQLPart('orderBy');
        $queryBuilder->resetDQLPart('orderBy');
        
        $queryBuilder->addOrderBy('SIZE(o.images)', 'DESC');
        
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

    /**
     * @return iterable<ProductInterface>
     */
    public function findByPhrase(string $phrase, string $locale, ?int $limit = null, ?ChannelInterface $channel = null): iterable
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

        $queryBuilder
            ->addOrderBy('SIZE(o.images)', 'DESC')
            ->addOrderBy('o.createdAt', 'DESC')
        ;

        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }

        /** @var iterable<ProductInterface> $result */
        $result = $queryBuilder->getQuery()->getResult();
        return $result;
    }
}
