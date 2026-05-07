<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Repository\ProductReviewRepositoryInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the approved customer reviews for a given product.
 * Called as a sub-request from the PDP accordion via render(controller(...)).
 */
final class ProductReviewController extends AbstractController
{
    public function __construct(
        private readonly ProductReviewRepositoryInterface $productReviewRepository,
        private readonly ChannelContextInterface $channelContext,
        private readonly LocaleContextInterface $localeContext,
    ) {}

    /**
     * Returns the rendered list of accepted reviews for a product identified by its slug.
     */
    public function listAction(string $slug): Response
    {
        $channel = $this->channelContext->getChannel();
        $locale  = $this->localeContext->getLocaleCode();

        $reviews = $this->productReviewRepository->findAcceptedByProductSlugAndChannel(
            $slug,
            $locale,
            $channel,
        );

        return $this->render('shop/ProductReview/_list.html.twig', [
            'reviews' => $reviews,
        ]);
    }
}
