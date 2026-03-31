<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Repository\ProductRepository;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SearchController extends AbstractController
{
    public function __construct(
        private ProductRepository $productRepository,
        private LocaleContextInterface $localeContext,
        private ChannelContextInterface $channelContext,
    ) {
    }

    public function search(Request $request): Response
    {
        $phrase = (string) $request->query->get('search', '');

        if ('' === $phrase) {
            return $this->redirectToRoute('sylius_shop_homepage');
        }

        /** @var \Sylius\Component\Core\Model\ChannelInterface $channel */
        $channel = $this->channelContext->getChannel();

        $products = $this->productRepository->findByPhrase(
            $phrase,
            $this->localeContext->getLocaleCode(),
            null,
            $channel
        );

        return $this->render('Shop/Search/results.html.twig', [
            'products' => $products,
            'phrase' => $phrase,
        ]);
    }
}
