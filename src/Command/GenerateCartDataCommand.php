<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
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
    name: 'app:generate-cart-data',
    description: 'Generate realistic semi-synthetic cart data (state=cart) for ML recommendation feeding.',
)]
class GenerateCartDataCommand extends Command
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
        $this->addOption('purge', null, InputOption::VALUE_NONE, 'Purge all existing carts (state = cart) before generating');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isPurge = (bool) $input->getOption('purge');

        /** @var Connection $conn */
        $conn = $this->entityManager->getConnection();

        $io->title('Starting cart data generation (ML Dataset)');

        if ($isPurge) {
            $io->warning('Purging existing carts (state = cart)...');
            // Using DBAL delete for speed and cascade
            $conn->executeStatement("DELETE FROM sylius_order WHERE state = 'cart'");
            $io->success('Old carts purged.');
        }

        // 1. Fetch channel WEB_EUR strictly
        $channelId = $conn->fetchOne("SELECT id FROM sylius_channel WHERE code = 'WEB_EUR'");
        if (!$channelId) {
            $io->error('Channel WEB_EUR not found.');
            return Command::FAILURE;
        }

        // 2. Load all Customers
        $customerIds = $conn->fetchFirstColumn('SELECT id FROM sylius_customer');
        if (empty($customerIds)) {
            $io->error('No customers found.');
            return Command::FAILURE;
        }

        // 3. Preload all active variants with their price from WEB_EUR
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
        $productToVariantMap = []; // To convert product_id to a valid variant_id
        
        foreach ($variantRows as $row) {
            $vid = (int)$row['variant_id'];
            $pid = (int)$row['product_id'];
            $price = (int)$row['price'];
            
            $validVariantIds[] = $vid;
            $variantPrices[$vid] = $price;
            if (!isset($productToVariantMap[$pid])) {
                $productToVariantMap[$pid] = [];
            }
            $productToVariantMap[$pid][] = $vid;
        }

        if (empty($validVariantIds)) {
            $io->error('No valid priced variations found for WEB_EUR.');
            return Command::FAILURE;
        }

        // 4. Preload historical user purchases (mapped to variant_id)
        $io->text('Pre-loading user purchases history...');
        $purchasesSql = "
            SELECT o.customer_id, i.variant_id
            FROM sylius_order_item i
            JOIN sylius_order o ON i.order_id = o.id
            WHERE o.customer_id IS NOT NULL AND i.variant_id IS NOT NULL 
              AND o.state != 'cart'
        ";
        $rawPurchases = $conn->fetchAllAssociative($purchasesSql);
        $customerPurchases = [];
        foreach ($rawPurchases as $row) {
            $cid = (int)$row['customer_id'];
            $vid = (int)$row['variant_id'];
            if (isset($variantPrices[$vid])) {
                $customerPurchases[$cid][] = $vid;
            }
        }
        foreach ($customerPurchases as $cid => $vids) {
            $customerPurchases[$cid] = array_values(array_unique($vids));
        }

        // 5. Preload Wishlist user history. Wishlist holds Product ID, we must map to Variant ID
        $io->text('Pre-loading user wishlist data...');
        $wishlistSql = "SELECT customer_id, product_id FROM cateco_wishlist_item";
        $rawWishlist = $conn->fetchAllAssociative($wishlistSql);
        $customerWishlist = [];
        foreach ($rawWishlist as $row) {
            $cid = (int)$row['customer_id'];
            $pid = (int)$row['product_id'];
            if (isset($productToVariantMap[$pid])) {
                // Select simply the first available variant for this product
                $vid = $productToVariantMap[$pid][0];
                $customerWishlist[$cid][] = $vid;
            }
        }
        foreach ($customerWishlist as $cid => $vids) {
            $customerWishlist[$cid] = array_values(array_unique($vids));
        }

        $now = time();
        $totalCartsInserted = 0;
        $totalItemsInserted = 0;
        $idx = 0;
        $batchSize = 50;

        /** @var ChannelInterface $channelProxy */
        $channelProxy = $this->entityManager->getReference(ChannelInterface::class, $channelId);

        $io->progressStart(count($customerIds));

        foreach ($customerIds as $customerId) {
            $customerId = (int) $customerId;
            
            // 1. Determine how many carts to create (0 to 3) - 10% chance of 0 carts for realism
            if (random_int(1, 100) <= 10) {
                $io->progressAdvance();
                continue; 
            }
            
            $numCarts = random_int(1, 3);

            for ($c = 0; $c < $numCarts; $c++) {
                /** @var OrderInterface $cart */
                $cart = $this->orderFactory->createNew();
                
                /** @var CustomerInterface $customerProxy */
                $customerProxy = $this->entityManager->getReference(CustomerInterface::class, $customerId);
                
                $cart->setCustomer($customerProxy);
                $cart->setChannel($channelProxy);
                $cart->setCurrencyCode('EUR');
                $cart->setLocaleCode('fr_FR');
                
                // Specific requested states
                $cart->setState('cart');
                $cart->setCheckoutState('cart');
                $cart->setPaymentState('awaiting_payment');
                $cart->setShippingState('cart'); // Or null, requested logic
                
                // Generate realistic Date
                // 80% chance of being in the last 7 days. 20% between 7 days and 30 days.
                if (random_int(1, 100) <= 80) {
                    $randomTimestamp = $now - random_int(1, 7 * 86400); // last 7 days
                } else {
                    $randomTimestamp = $now - random_int(8 * 86400, 30 * 86400); // 8-30 days
                }
                
                $cartDate = new \DateTime();
                $cartDate->setTimestamp($randomTimestamp);
                $cart->setCreatedAt($cartDate);
                $cart->setUpdatedAt($cartDate);

                // Initialize cart items (1 to 5)
                $numItems = random_int(1, 5);
                $selectedVariantIds = [];

                $userWishlist = $customerWishlist[$customerId] ?? [];
                $userPurchases = $customerPurchases[$customerId] ?? [];

                // Weighted product selection logic (30-40% wishlist, 20-30% purchased, 30-50% random)
                for ($i = 0; $i < $numItems; $i++) {
                    $roll = random_int(1, 100);
                    $variantIdStr = null;

                    if ($roll <= 35 && !empty($userWishlist)) {
                        // Wishlist selection
                        $variantIdStr = $userWishlist[array_rand($userWishlist)];
                    } elseif ($roll <= 60 && !empty($userPurchases)) {
                        // Purchase selection
                        $variantIdStr = $userPurchases[array_rand($userPurchases)];
                    } else {
                        // Random discovery
                        $variantIdStr = $validVariantIds[array_rand($validVariantIds)];
                    }

                    // Prevent duplicates in the same cart
                    if (isset($selectedVariantIds[$variantIdStr])) {
                        // Fallback constraint logic: keep trying random until unique
                        $attempts = 0;
                        while (isset($selectedVariantIds[$variantIdStr]) && $attempts < 10) {
                            $variantIdStr = $validVariantIds[array_rand($validVariantIds)];
                            $attempts++;
                        }
                    }

                    if (!isset($selectedVariantIds[$variantIdStr])) {
                        $selectedVariantIds[$variantIdStr] = true;

                        /** @var OrderItemInterface $orderItem */
                        $orderItem = $this->orderItemFactory->createNew();
                        
                        /** @var ProductVariantInterface $variantProxy */
                        $variantProxy = $this->entityManager->getReference(ProductVariantInterface::class, $variantIdStr);
                        
                        $quantity = random_int(1, 3);
                        $unitPrice = $variantPrices[$variantIdStr];

                        // Use reflection to bypass missing sylius setters for ML simulation
                        $this->forceOrderItemValues($orderItem, $variantProxy, $quantity, $unitPrice);
                        
                        $cart->addItem($orderItem);
                        $totalItemsInserted++;
                    }
                }

                // Compute order total synchronously
                $cartItemsTotal = 0;
                foreach ($cart->getItems() as $item) {
                    $cartItemsTotal += $item->getTotal();
                }
                
                // Set directly via reflection or trust recalculation
                // Sylius calculates these automatically if we use proper order modifier, but let's force it for strict DB integrity
                $this->forceOrderTotals($cart, $cartItemsTotal);

                $this->entityManager->persist($cart);
                $totalCartsInserted++;

                // Memory flush logic
                $idx++;
                if (($idx % $batchSize) === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                    
                    // Re-fetch dictionary proxies after clear
                    $channelProxy = $this->entityManager->getReference(ChannelInterface::class, $channelId);
                }
            }

            $io->progressAdvance();
        }

        $this->entityManager->flush(); // Final flush
        $this->entityManager->clear();

        $io->progressFinish();
        $io->success(sprintf("Cart data generation successful!\n- Carts generated: %d\n- Order Items Generated: %d", $totalCartsInserted, $totalItemsInserted));

        return Command::SUCCESS;
    }

    /**
     * Bypasses state machine listeners via Reflection to force exact totals calculated explicitly,
     * ensuring ML training dataset is mathematically perfect.
     */
    private function forceOrderTotals(OrderInterface $cart, int $itemsTotal): void
    {
        $reflection = new \ReflectionClass($cart);
        
        $itemsTotalProp = $reflection->getProperty('itemsTotal');
        $itemsTotalProp->setAccessible(true);
        $itemsTotalProp->setValue($cart, $itemsTotal);

        $totalProp = $reflection->getProperty('total');
        $totalProp->setAccessible(true);
        $totalProp->setValue($cart, $itemsTotal);
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
