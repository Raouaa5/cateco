<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order\OrderItem;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class CartItemRemoveController extends AbstractController
{
    public function __construct(
        private readonly CartContextInterface $cartContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderProcessorInterface $orderProcessor,
    ) {
    }

    public function __invoke(int $id, Request $request): RedirectResponse
    {
        try {
            // Load the cart item directly via Doctrine (guaranteed to be a managed entity)
            /** @var OrderItem|null $item */
            $item = $this->entityManager->find(OrderItem::class, $id);

            if ($item !== null) {
                // Security: ensure the item belongs to the current user's cart
                $cart = $this->cartContext->getCart();
                if ($item->getOrder() && $item->getOrder()->getId() === $cart->getId()) {
                    $order = $item->getOrder();

                    // Remove from the order's collection (triggers orphanRemoval)
                    $order->removeItem($item);

                    // Explicitly mark for deletion to be safe
                    $this->entityManager->remove($item);

                    // Re-process the order to recalculate totals
                    $this->orderProcessor->process($order);

                    // Persist everything
                    $this->entityManager->flush();
                }
            }
        } catch (\Throwable $e) {
            // Log but don't crash — redirect user back regardless
            error_log('[CartItemRemove] Error: ' . $e->getMessage());
        }

        $referer = $request->headers->get('referer');
        if (!$referer) {
            $referer = $this->generateUrl('sylius_shop_homepage', ['_locale' => $request->getLocale()]);
        }

        return $this->redirect($referer);
    }
}
