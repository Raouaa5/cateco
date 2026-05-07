<?php

declare(strict_types=1);

namespace App\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Storage\CartStorageInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

/**
 * After a successful shop login, restores the customer's most recent DB cart
 * into the Sylius session-based cart storage so it appears in the UI.
 */
final class CartRestorationListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CartStorageInterface $cartStorage,
        private readonly ChannelContextInterface $channelContext,
    ) {
    }

    public function onSecurityInteractiveLogin(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();

        // Only act for shop customers
        if (!$user instanceof ShopUserInterface) {
            return;
        }

        $customer = $user->getCustomer();
        if ($customer === null) {
            return;
        }

        try {
            $channel = $this->channelContext->getChannel();
        } catch (\Exception) {
            return;
        }

        // If there's already an active cart in session, don't overwrite it
        if ($this->cartStorage->hasForChannel($channel)) {
            return;
        }

        // Find the customer's most recent non-empty cart
        $result = $this->entityManager->getConnection()->fetchAssociative(
            "SELECT o.id
             FROM sylius_order o
             INNER JOIN sylius_order_item oi ON oi.order_id = o.id
             WHERE o.customer_id = :customerId
               AND o.state = 'cart'
               AND o.channel_id = :channelId
             GROUP BY o.id
             HAVING COUNT(oi.id) > 0
             ORDER BY o.created_at DESC
             LIMIT 1",
            [
                'customerId' => $customer->getId(),
                'channelId'  => $channel->getId(),
            ]
        );

        if ($result === false || empty($result['id'])) {
            return;
        }

        // Fetch the actual order entity and store it in session
        $cartRepository = $this->entityManager->getRepository(\Sylius\Component\Core\Model\Order::class);
        /** @var \Sylius\Component\Core\Model\OrderInterface|null $cart */
        $cart = $cartRepository->find((int) $result['id']);

        if ($cart === null) {
            return;
        }

        $this->cartStorage->setForChannel($channel, $cart);
    }
}
