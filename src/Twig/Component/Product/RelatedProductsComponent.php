<?php

declare(strict_types=1);

namespace App\Twig\Component\Product;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\TwigHooks\Twig\Component\HookableComponentTrait;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

#[AsTwigComponent]
class RelatedProductsComponent
{
    use HookableComponentTrait;

    public const DEFAULT_LIMIT = 12;

    public int $limit = self::DEFAULT_LIMIT;

    public string $title = 'Articles similaires';

    /** @var ProductInterface */
    public ProductInterface $product;

    /** @param ProductRepositoryInterface<ProductInterface> $productRepository */
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private LocaleContextInterface $localeContext,
        private ChannelContextInterface $channelContext,
    ) {
    }

    /**
     * @return array<ProductInterface>
     */
    #[ExposeInTemplate(name: 'related_products')]
    public function getRelatedProducts(): array
    {
        $relatedProducts = [];
        /** @var ChannelInterface $channel */
        $channel = $this->channelContext->getChannel();

        foreach ($this->product->getAssociations() as $association) {
            foreach ($association->getAssociatedProducts() as $associatedProduct) {
                if ($associatedProduct->isEnabled() && $associatedProduct->hasChannel($channel)) {
                    $relatedProducts[$associatedProduct->getId()] = $associatedProduct;
                }
            }
        }

        if (count($relatedProducts) >= $this->limit) {
            return array_slice(array_values($relatedProducts), 0, $this->limit);
        }

        $mainTaxon = $this->product->getMainTaxon();
        if (null === $mainTaxon) {
            $mainTaxon = $this->product->getTaxons()->first() ?: null;
        }

        if (null !== $mainTaxon) {
            $localeCode = $this->localeContext->getLocaleCode();
            $queryBuilder = $this->productRepository->createShopListQueryBuilder(
                $channel,
                $mainTaxon,
                $localeCode
            );

            $excludedIds = array_keys($relatedProducts);
            $excludedIds[] = $this->product->getId();

            $queryBuilder
                ->andWhere('o.id NOT IN (:excludedIds)')
                ->setParameter('excludedIds', $excludedIds)
                ->setMaxResults($this->limit - count($relatedProducts))
            ;

            /** @var array<ProductInterface> $categoryProducts */
            $categoryProducts = $queryBuilder->getQuery()->getResult();
            foreach ($categoryProducts as $cp) {
                $relatedProducts[$cp->getId()] = $cp;
            }
        }

        // 3. Ultimate Fallback: if no associated or category products exist, fetch the latest catalog products.
        if (empty($relatedProducts)) {
            $localeCode = $this->localeContext->getLocaleCode();
            $fallbackQb = $this->productRepository->createQueryBuilder('o')
                ->addSelect('translation')
                ->innerJoin('o.translations', 'translation', 'WITH', 'translation.locale = :locale')
                ->andWhere(':channel MEMBER OF o.channels')
                ->andWhere('o.enabled = :enabled')
                ->andWhere('o.id != :excludedId')
                ->addOrderBy('o.createdAt', 'DESC')
                ->setParameter('channel', $channel)
                ->setParameter('locale', $localeCode)
                ->setParameter('enabled', true)
                ->setParameter('excludedId', $this->product->getId())
                ->setMaxResults($this->limit);
                
            $relatedProducts = $fallbackQb->getQuery()->getResult();
        }

        return array_values($relatedProducts);
    }
}
