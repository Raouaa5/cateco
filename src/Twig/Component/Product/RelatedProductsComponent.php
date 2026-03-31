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
        $mainTaxon = $this->product->getMainTaxon();

        if (null === $mainTaxon) {
            return [];
        }

        /** @var ChannelInterface $channel */
        $channel = $this->channelContext->getChannel();
        $localeCode = $this->localeContext->getLocaleCode();

        $queryBuilder = $this->productRepository->createShopListQueryBuilder(
            $channel,
            $mainTaxon,
            $localeCode
        );

        $queryBuilder
            ->andWhere('o.id != :excludedProductId')
            ->setParameter('excludedProductId', $this->product->getId())
            ->setMaxResults($this->limit)
        ;

        /** @var array<ProductInterface> $result */
        $result = $queryBuilder->getQuery()->getResult();
        return $result;
    }
}
