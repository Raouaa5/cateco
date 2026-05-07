<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Addressing\Model\AddressInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-orders-data',
    description: 'Generate realistic semi-synthetic final orders data for ML recommendation engine.',
)]
class GenerateOrdersDataCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FactoryInterface $orderFactory,
        private FactoryInterface $orderItemFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purge-generated', null, InputOption::VALUE_NONE, 'Purge ONLY orders identified by [ML_AUTO_GENERATED] in notes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isPurge = (bool) $input->getOption('purge-generated');

        /** @var Connection $conn */
        $conn = $this->entityManager->getConnection();

        $io->title('Starting Realistic Orders Data Generation');

        if ($isPurge) {
            $io->warning('Purging ONLY previously generated ML orders...');
            $affected = $conn->executeStatement("DELETE FROM sylius_order WHERE notes = '[ML_AUTO_GENERATED]'");
            $io->success(sprintf('Purged %d generated orders.', $affected));
        }

        // 1. Fetch channel WEB_EUR strictly
        $channelId = $conn->fetchOne("SELECT id FROM sylius_channel WHERE code = 'WEB_EUR'");
        if (!$channelId) {
            $io->error('Channel WEB_EUR not found.');
            return Command::FAILURE;
        }

        // 2. We will create fresh address clones for each order to bypass UNIQUE constraints
        // Using App\Entity\Addressing\Address directly in the loop.

        // 3. Load all Customers
        $customerIds = $conn->fetchFirstColumn('SELECT id FROM sylius_customer');
        if (empty($customerIds)) {
            $io->error('No customers found.');
            return Command::FAILURE;
        }

        // 4. Preload all active variants with their price from WEB_EUR
        $io->text('Pre-loading variant catalogue pricing...');
        $variantsSql = "
            SELECT v.id as variant_id, cp.price, v.product_id 
            FROM sylius_product_variant v
            JOIN sylius_product p ON v.product_id = p.id
            JOIN sylius_channel_pricing cp ON cp.product_variant_id = v.id
            WHERE p.enabled = 1 AND cp.channel_code = 'WEB_EUR' AND cp.price IS NOT NULL
        ";
        $variantRows = $conn->fetchAllAssociative($variantsSql);
        
        $validVariantIds = [];
        $variantPrices = [];
        $productToVariantMap = [];
        
        foreach ($variantRows as $row) {
            $vid = (int)$row['variant_id'];
            $validVariantIds[] = $vid;
            $variantPrices[$vid] = (int)$row['price'];
            $productToVariantMap[(int)$row['product_id']][] = $vid;
        }

        // 5. Build Popular vs Less Popular for Random Discovery mix
        $popularSql = '
            SELECT v.id
            FROM sylius_order_item i
            JOIN sylius_product_variant v ON i.variant_id = v.id
            GROUP BY v.id
            ORDER BY COUNT(*) DESC
            LIMIT 50
        ';
        $popularVariantIds = $conn->fetchFirstColumn($popularSql);
        if (empty($popularVariantIds)) {
            $popularVariantIds = array_slice($validVariantIds, 0, 50);
        }

        // 6. Preload Wishlist history mapped to Variant ID
        $io->text('Pre-loading user wishlist data...');
        $wishlistSql = "SELECT customer_id, product_id FROM cateco_wishlist_item";
        $rawWishlist = $conn->fetchAllAssociative($wishlistSql);
        $customerWishlist = [];
        foreach ($rawWishlist as $row) {
            $cid = (int)$row['customer_id'];
            $pid = (int)$row['product_id'];
            if (isset($productToVariantMap[$pid])) {
                $customerWishlist[$cid][] = $productToVariantMap[$pid][0];
            }
        }

        // 7. Preload Cart history
        $io->text('Pre-loading user cart history...');
        $cartSql = "
            SELECT o.customer_id, i.variant_id
            FROM sylius_order_item i
            JOIN sylius_order o ON i.order_id = o.id
            WHERE o.customer_id IS NOT NULL AND i.variant_id IS NOT NULL AND o.state = 'cart'
        ";
        $rawCarts = $conn->fetchAllAssociative($cartSql);
        $customerCarts = [];
        foreach ($rawCarts as $row) {
            $customerCarts[(int)$row['customer_id']][] = (int)$row['variant_id'];
        }

        $now = time();
        $OneYearAgo = clone (new \DateTime())->modify('-12 months');
        
        $totalOrdersInserted = 0;
        $totalItemsInserted = 0;
        $idx = 0;
        $batchSize = 25; // Smaller batch for orders (heavy objects)

        /** @var ChannelInterface $channelProxy */
        $channelProxy = $this->entityManager->getReference(ChannelInterface::class, $channelId);

        $io->progressStart(count($customerIds));

        foreach ($customerIds as $customerId) {
            $customerId = (int) $customerId;
            
            // A. Customer Activity Distribution
            $roll = random_int(1, 100);
            if ($roll <= 10) {
                $numOrders = random_int(5, 10); // Whales
            } elseif ($roll <= 40) {
                $numOrders = random_int(2, 4); // Medium
            } elseif ($roll <= 80) {
                $numOrders = 1; // Light
            } else {
                // Inactive (20%)
                $io->progressAdvance();
                continue; 
            }

            $userWishlist = $customerWishlist[$customerId] ?? [];
            $userCarts = $customerCarts[$customerId] ?? [];

            // Tracking to limit max 3 repeats of the same variant per user
            $userVariantPurchases = [];

            // B. Order Creation Loop
            for ($o = 0; $o < $numOrders; $o++) {
                /** @var OrderInterface $order */
                $order = $this->orderFactory->createNew();
                
                /** @var CustomerInterface $customerProxy */
                $customerProxy = $this->entityManager->getReference(CustomerInterface::class, $customerId);
                
                $order->setCustomer($customerProxy);
                $order->setChannel($channelProxy);
                $order->setCurrencyCode('EUR');
                $order->setLocaleCode('fr_FR');
                $order->setNotes('[ML_AUTO_GENERATED]');
                $order->setNumber('SIM-' . strtoupper(substr(md5((string)random_int(1, 10000000) . microtime()), 0, 8)));
                $order->setTokenValue('tok_' . bin2hex(random_bytes(10)));
                
                $address = new \App\Entity\Addressing\Address();
                $address->setFirstName($customerProxy->getFirstName() ?: 'Client');
                $address->setLastName($customerProxy->getLastName() ?: 'Cateco');
                $address->setStreet('Simulation ML');
                $address->setCity('Cayenne');
                $address->setPostcode('97300');
                $address->setCountryCode('FR');

                $order->setShippingAddress($address);
                $order->setBillingAddress(clone $address);

                // Safe Sylius States (using proper badge tracking order states)
                $validStates = ['new', 'fulfilled'];
                $order->setState($validStates[array_rand($validStates)]);
                $order->setCheckoutState('completed');
                
                $paymentStates = ['paid', 'paid', 'paid', 'partially_refunded']; // Weighting towards paid
                $order->setPaymentState($paymentStates[array_rand($paymentStates)]);
                
                $shippingStates = ['shipped', 'shipped', 'delivered', 'delivered', 'ready'];
                $order->setShippingState($shippingStates[array_rand($shippingStates)]);
                
                // C. Realistic Temporal Distribution (Curved towards recent months)
                // random_float^2 biases towards 0. We map 0 to now, 1 to 12 months ago
                $bias = pow(mt_rand() / mt_getrandmax(), 2);
                $secondsAgo = (int) ($bias * (365 * 86400));
                
                // Burst Logic (if second order or more, 30% chance it happens within the same week as the first one)
                // Handled simplistically by just clamping to a small range occasionally
                if ($o > 0 && random_int(1, 100) <= 30) {
                    $secondsAgo = max(0, $secondsAgo - random_int(1000, 604800)); // +/- 1 week relative 
                }

                $orderDate = new \DateTime();
                $orderDate->setTimestamp($now - $secondsAgo);
                $order->setCreatedAt($orderDate);
                $order->setUpdatedAt($orderDate);
                $order->setCheckoutCompletedAt($orderDate);

                // D. Build Order Items (1 to 4)
                $numItems = random_int(1, 4);
                $orderVariantIds = []; // Track within this order to avoid duplicates

                for ($i = 0; $i < $numItems; $i++) {
                    $itemRoll = random_int(1, 100);
                    $variantId = null;

                    // 40% Wishlist, 30% Cart, 30% Random
                    if ($itemRoll <= 40 && !empty($userWishlist)) {
                        $variantId = $userWishlist[array_rand($userWishlist)];
                    } elseif ($itemRoll <= 70 && !empty($userCarts)) {
                        $variantId = $userCarts[array_rand($userCarts)];
                    } else {
                        // Mix Popular & Unpopular
                        if (random_int(1, 100) <= 50 && !empty($popularVariantIds)) {
                            $variantId = $popularVariantIds[array_rand($popularVariantIds)];
                        } else {
                            $variantId = $validVariantIds[array_rand($validVariantIds)];
                        }
                    }

                    // Enforce Re-purchase constraints: max 3 per user overall, max 1 per cart
                    $attempts = 0;
                    while (
                        $attempts < 20 &&
                        (isset($orderVariantIds[$variantId]) || ($userVariantPurchases[$variantId] ?? 0) >= 3)
                    ) {
                        $variantId = $validVariantIds[array_rand($validVariantIds)];
                        $attempts++;
                    }

                    if (!isset($orderVariantIds[$variantId]) && ($userVariantPurchases[$variantId] ?? 0) < 3) {
                        $orderVariantIds[$variantId] = true;
                        if (!isset($userVariantPurchases[$variantId])) {
                            $userVariantPurchases[$variantId] = 0;
                        }
                        $userVariantPurchases[$variantId]++;

                        /** @var OrderItemInterface $orderItem */
                        $orderItem = $this->orderItemFactory->createNew();
                        /** @var ProductVariantInterface $variantProxy */
                        $variantProxy = $this->entityManager->getReference(ProductVariantInterface::class, $variantId);
                        
                        $quantity = random_int(1, 3);
                        $unitPrice = $variantPrices[$variantId];

                        // Use reflection to bypass missing sylius setters for ML simulation
                        $this->forceOrderItemValues($orderItem, $variantProxy, $quantity, $unitPrice);
                        
                        $order->addItem($orderItem);
                        $totalItemsInserted++;
                    }
                }

                // If strict avoidance caused no items, at least push 1 random item
                if ($order->getItems()->count() === 0) {
                    $variantId = $validVariantIds[array_rand($validVariantIds)];
                    
                    /** @var OrderItemInterface $fallbackItem */
                    $fallbackItem = $this->orderItemFactory->createNew();
                    $variantProxy = $this->entityManager->getReference(ProductVariantInterface::class, $variantId);
                    
                    $unitPrice = $variantPrices[$variantId];
                    // Use reflection to bypass missing sylius setters for ML simulation
                    $this->forceOrderItemValues($fallbackItem, $variantProxy, 1, $unitPrice);
                    
                    $order->addItem($fallbackItem);
                    $totalItemsInserted++;
                }

                // Totals Computation explicitly
                $orderItemsTotal = 0;
                foreach ($order->getItems() as $item) {
                    $orderItemsTotal += $item->getTotal();
                }
                $this->forceOrderTotals($order, $orderItemsTotal);

                $this->entityManager->persist($order);
                $totalOrdersInserted++;

                // Memory Flush Layer
                $idx++;
                if (($idx % $batchSize) === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    
                    // Restore global proxies after clear
                    $channelProxy = $this->entityManager->getReference(ChannelInterface::class, $channelId);
                }
            }

            $io->progressAdvance();
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $io->progressFinish();
        $io->success(sprintf("Realistic Orders generation successful!\n- Orders generated: %d\n- Order Items Generated: %d", $totalOrdersInserted, $totalItemsInserted));

        return Command::SUCCESS;
    }

    private function forceOrderTotals(OrderInterface $order, int $itemsTotal): void
    {
        $reflection = new \ReflectionClass($order);
        
        $itemsTotalProp = $reflection->getProperty('itemsTotal');
        $itemsTotalProp->setAccessible(true);
        $itemsTotalProp->setValue($order, $itemsTotal);

        $totalProp = $reflection->getProperty('total');
        $totalProp->setAccessible(true);
        $totalProp->setValue($order, $itemsTotal);
    }

    private function forceOrderItemValues(OrderItemInterface $orderItem, ProductVariantInterface $variant, int $qty, int $price): void
    {
        $orderItem->setVariant($variant); // Sylius usually has setVariant

        $reflection = new \ReflectionClass($orderItem);
        $qtyProp = $reflection->getProperty('quantity');
        $qtyProp->setAccessible(true);
        $qtyProp->setValue($orderItem, $qty);

        $unitPriceProp = $reflection->getProperty('unitPrice');
        $unitPriceProp->setAccessible(true);
        $unitPriceProp->setValue($orderItem, $price);

        $totalProp = $reflection->getProperty('total');
        $totalProp->setAccessible(true);
        $totalProp->setValue($orderItem, $qty * $price);
    }
}
