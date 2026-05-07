<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-wishlist-data',
    description: 'Generate semi-synthetic realistic wishlist data for AI recommendation feeding.',
)]
class GenerateWishlistDataCommand extends Command
{
    private const MIN_ITEMS_PER_USER = 3;
    private const MAX_ITEMS_PER_USER = 10;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Purge the entire wishlist table before generating');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isPurge = (bool) $input->getOption('purge');

        /** @var Connection $conn */
        $conn = $this->entityManager->getConnection();

        $io->title('Starting Wishlist Data Generation');

        if ($isPurge) {
            $io->warning('Purging the table cateco_wishlist_item...');
            $conn->executeQuery('DELETE FROM cateco_wishlist_item');
            $io->success('Table purged.');
        }

        // 1. Fetch all customer IDs
        $customerIds = $conn->fetchFirstColumn('SELECT id FROM sylius_customer');
        if (empty($customerIds)) {
            $io->error('No customers found in database.');
            return Command::FAILURE;
        }
        $io->text(sprintf('Found %d customers.', count($customerIds)));

        // 2. Fetch all product IDs
        $allProductIds = $conn->fetchFirstColumn('SELECT id FROM sylius_product WHERE enabled = 1');
        if (empty($allProductIds)) {
            $io->error('No enabled products found in database.');
            return Command::FAILURE;
        }

        // 3. Identify Top Popular Products (Top 50)
        $popularSql = '
            SELECT v.product_id
            FROM sylius_order_item i
            JOIN sylius_product_variant v ON i.variant_id = v.id
            GROUP BY v.product_id
            ORDER BY COUNT(*) DESC
            LIMIT 50
        ';
        $popularProductIds = $conn->fetchFirstColumn($popularSql);

        // Fallback if no popular products exist
        if (empty($popularProductIds)) {
            $popularProductIds = array_slice($allProductIds, 0, 50);
        }

        // 4. Preload all purchases grouped by customer
        $io->text('Pre-loading customer purchase history...');
        $purchasesSql = '
            SELECT o.customer_id, v.product_id
            FROM sylius_order_item i
            JOIN sylius_order o ON i.order_id = o.id
            JOIN sylius_product_variant v ON i.variant_id = v.id
            WHERE o.customer_id IS NOT NULL AND v.product_id IS NOT NULL
        ';
        $rawPurchases = $conn->fetchAllAssociative($purchasesSql);
        
        $customerPurchases = [];
        foreach ($rawPurchases as $row) {
            $cid = (int)$row['customer_id'];
            $pid = (int)$row['product_id'];
            $customerPurchases[$cid][] = $pid;
        }

        // Distinct purchases per user
        foreach ($customerPurchases as $cid => $pids) {
            $customerPurchases[$cid] = array_values(array_unique($pids));
        }

        // 5. Generation loop
        $io->progressStart(count($customerIds));
        
        $totalInserted = 0;
        $batchSize = 1000;
        $idx = 0;

        $conn->beginTransaction();

        try {
            foreach ($customerIds as $customerId) {
                $customerId = (int) $customerId;
                $targetSize = random_int(self::MIN_ITEMS_PER_USER, self::MAX_ITEMS_PER_USER);
                $selectedProductIds = [];

                // A. Add 1 to 3 items from already purchased
                $purchased = $customerPurchases[$customerId] ?? [];
                if (!empty($purchased)) {
                    $purchasedCount = min(count($purchased), random_int(1, 3));
                    $keys = (array) array_rand($purchased, $purchasedCount);
                    foreach ($keys as $key) {
                        $selectedProductIds[$purchased[$key]] = true;
                    }
                }

                // B. Add 1 to 2 popular products
                $popularCount = random_int(1, 2);
                $attempts = 0;
                $addedPopular = 0;
                while ($addedPopular < $popularCount && $attempts < 10) {
                    $attempts++;
                    $randPopId = $popularProductIds[array_rand($popularProductIds)];
                    if (!isset($selectedProductIds[$randPopId])) {
                        $selectedProductIds[$randPopId] = true;
                        $addedPopular++;
                    }
                }

                // C. Fill remaining to reach targetSize
                $attempts = 0;
                while (count($selectedProductIds) < $targetSize && $attempts < 50) {
                    $attempts++;
                    $randId = $allProductIds[array_rand($allProductIds)];
                    if (!isset($selectedProductIds[$randId])) {
                        $selectedProductIds[$randId] = true;
                    }
                }

                // D. Insert items
                $now = time();
                foreach (array_keys($selectedProductIds) as $productId) {
                    // Generate a date between 1 and 180 days ago
                    $randomTimestamp = $now - random_int(86400, 180 * 86400);
                    $createdAt = date('Y-m-d H:i:s', $randomTimestamp);

                    // Insert ignore is not standard doctrine, but we know uniqueness holds based on array keys and purge
                    // We'll just execute standard insert. If it crashes on unique constraint because --purge wasn't used, we catch it.
                    try {
                        $conn->insert('cateco_wishlist_item', [
                            'customer_id' => $customerId,
                            'product_id'  => $productId,
                            'created_at'  => $createdAt,
                        ]);
                        $totalInserted++;
                        $idx++;
                    } catch (\Exception $e) {
                        // Likely a duplicate if --purge wasn't used, safely ignore
                    }

                    if (($idx % $batchSize) === 0) {
                        $conn->commit();
                        $conn->beginTransaction();
                    }
                }

                $io->progressAdvance();
            }

            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            $io->error('An error occurred during generation: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->progressFinish();
        $io->success(sprintf('Data generation successful! %d realistic wishlist items inserted.', $totalInserted));

        // Create the task to keep track of work
        return Command::SUCCESS;
    }
}
