<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\OdooJsonRpcClient;
use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\GenericEvent;

#[AsEventListener(event: 'sylius.order.post_complete', priority: 0)]
class OrderPlacedListener
{
    public function __construct(
        private readonly OdooJsonRpcClient $odooClient,
        private readonly LoggerInterface $odooLogger
    ) {}

    public function __invoke(GenericEvent $event): void
    {
        $order = $event->getSubject();

        if (!$order instanceof OrderInterface) {
            return;
        }

        try {
            $customer = $order->getCustomer();
            if (!$customer instanceof CustomerInterface) {
                return;
            }

            // 1. Prepare Customer mapping
            $email = (string) $customer->getEmail();
            $firstName = (string) $customer->getFirstName();
            $lastName = (string) $customer->getLastName();
            $name = trim($firstName . ' ' . $lastName);
            $phone = $customer->getPhoneNumber();

            // Link to partner
            $partnerId = $this->odooClient->createOrUpdatePartner(
                $name ?: 'Sylius Customer',
                $email,
                $phone
            );

            // 2. Prepare Items mapping
            $items = [];
            foreach ($order->getItems() as $item) {
                // Fetch strictly by SKU
                $variant = $item->getVariant();
                $sku = $variant ? $variant->getCode() : null;

                $items[] = [
                    'sku'          => $sku,
                    'product_name' => $item->getProductName(),
                    'quantity'     => $item->getQuantity(),
                    'unit_price'   => $item->getUnitPrice() / 100, // Sylius stores integer cents
                ];
            }

            // 3. Resolve base values
            $totalFloat = $order->getTotal() / 100;
            $currencyCode = $order->getCurrencyCode() ?? 'EUR';

            // 4. Synchronize Sale Order
            $saleOrderId = $this->odooClient->createSaleOrder(
                $partnerId,
                $items,
                $totalFloat,
                $currencyCode
            );

            // 5. Success Logging
            $this->odooLogger->info('Odoo sale order created successfully', [
                'sylius_order_id' => $order->getId(),
                'odoo_order_id'   => $saleOrderId,
                'partner_id'      => $partnerId,
            ]);

        } catch (\Exception $e) {
            // Log exactly what happened without crashing the Sylius redirect response
            $this->odooLogger->error('Failed to sync Order with Odoo', [
                'sylius_order_id' => $order->getId(),
                'error'           => $e->getMessage(),
            ]);
        }
    }
}
