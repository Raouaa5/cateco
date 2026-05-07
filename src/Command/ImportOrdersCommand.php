<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Addressing\Address;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:import-orders',
    description: 'Import orders from a CSV file into Sylius',
)]
class ImportOrdersCommand extends Command
{
    private const DEFAULT_CHANNEL_CODE  = 'WEB_EUR';
    private const PAYMENT_METHOD_CODE   = 'bank_transfer';
    private const SHIPPING_METHOD_CODE  = 'cateco';
    private const BATCH_SIZE            = 50;
    private const DEFAULT_COUNTRY_CODE  = 'FR';
    private const DEFAULT_CITY          = 'Cayenne';
    private const DEFAULT_POSTCODE      = '97300';
    private const DEFAULT_STREET        = 'Adresse inconnue';

    public function __construct(
        #[Autowire(service: 'sylius.factory.customer')]
        private FactoryInterface $customerFactory,
        private CustomerRepositoryInterface $customerRepository,
        private ChannelRepositoryInterface $channelRepository,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the CSV file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate without persisting changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $isDryRun = (bool) $input->getOption('dry-run');
        $filePath = $input->getArgument('file');

        if (!file_exists($filePath) || !is_readable($filePath)) {
            $io->error(sprintf('File "%s" does not exist or is not readable.', $filePath));
            return Command::FAILURE;
        }

        /** @var Connection $conn */
        $conn = $this->entityManager->getConnection();

        if ($isDryRun) {
            $io->warning('DRY-RUN mode: no changes will be saved.');
            $conn->beginTransaction();
        }

        // 1. Resolve Channel
        $channel = $this->channelRepository->findOneByCode(self::DEFAULT_CHANNEL_CODE);
        if (!$channel) {
            $io->error(sprintf('Channel "%s" not found.', self::DEFAULT_CHANNEL_CODE));
            return Command::FAILURE;
        }
        $channelId = $channel->getId();

        // 2. Resolve Payment Method ID (raw SQL — fastest approach)
        $paymentMethodId = $conn->fetchOne(
            'SELECT id FROM sylius_payment_method WHERE code = ?',
            [self::PAYMENT_METHOD_CODE]
        );

        // 3. Resolve Shipping Method ID
        $shippingMethodId = $conn->fetchOne(
            'SELECT id FROM sylius_shipping_method WHERE code = ?',
            [self::SHIPPING_METHOD_CODE]
        );

        // 4. Open file
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $io->error('Cannot open the file.');
            return Command::FAILURE;
        }
        $header = fgetcsv($handle, 0, ',');
        if ($header === false) {
            $io->error('The CSV file is empty or has no header.');
            return Command::FAILURE;
        }

        $io->title('Starting Orders Import');
        $io->progressStart();

        $i                = 0;
        $importedCount    = 0;
        $skippedCount     = 0;
        $failedCount      = 0;
        $processedNumbers = [];

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            if (count($data) < 11) {
                $io->warning(sprintf('Row %d has insufficient columns, skipped.', $i + 2));
                $failedCount++;
                continue;
            }

            [
                $number, $client, $total, $itemsTotal, $state, $paymentState,
                $shippingState, $createdAt, $currencyCode, $localeCode, $createdByGuest
            ] = $data;

            $number         = trim($number);
            $client         = trim($client);
            $total          = (int) trim($total);
            $itemsTotal     = (int) trim($itemsTotal);
            $state          = trim($state);
            $paymentState   = trim($paymentState);
            $shippingState  = trim($shippingState);
            $createdAtStr   = trim($createdAt);
            $currencyCode   = trim($currencyCode);
            $localeCode     = trim($localeCode);
            $createdByGuest = (int) trim($createdByGuest);

            // Skip intra-file duplicates
            if (in_array($number, $processedNumbers, true)) {
                $io->warning(sprintf('Order #%s is duplicated in the CSV, skipped.', $number));
                $skippedCount++;
                continue;
            }

            // Skip if already exists in DB
            $exists = $conn->fetchOne('SELECT id FROM sylius_order WHERE number = ?', [$number]);
            if ($exists) {
                $skippedCount++;
                $processedNumbers[] = $number;
                continue;
            }

            try {
                $createdAtObj = !empty($createdAtStr) ? new \DateTime($createdAtStr) : new \DateTime();
            } catch (\Exception) {
                $createdAtObj = new \DateTime();
            }
            $createdAtFormatted = $createdAtObj->format('Y-m-d H:i:s');

            // Find or create Customer (ORM needed for proper Sylius entity)
            try {
                $customer   = $this->findOrCreateCustomer($client);
                $customerId = $customer->getId();

                // Create Address (ORM for proper FK relation)
                $address = $this->createDefaultAddress($customer);
                $this->entityManager->persist($address);
                $this->entityManager->flush();
                $addressId = $address->getId();
            } catch (\Exception $e) {
                $io->warning(sprintf('Row %d: Customer/Address error — %s', $i + 2, $e->getMessage()));
                $failedCount++;
                continue;
            }

            $token = $this->generateToken();

            // Insert Order via raw DBAL (avoids Sylius state machine restrictions)
            try {
                $conn->insert('sylius_order', [
                    'number'                  => $number,
                    'customer_id'             => $customerId,
                    'channel_id'              => $channelId,
                    'shipping_address_id'     => $addressId,
                    'billing_address_id'      => $addressId,
                    'total'                   => $total,
                    'items_total'             => $itemsTotal,
                    'adjustments_total'       => 0,
                    'state'                   => $state,
                    'checkout_state'          => 'completed',
                    'payment_state'           => $paymentState,
                    'shipping_state'          => $shippingState,
                    'currency_code'           => $currencyCode,
                    'locale_code'             => $localeCode,
                    'created_by_guest'        => $createdByGuest,
                    'token_value'             => $token,
                    'created_at'              => $createdAtFormatted,
                    'updated_at'              => $createdAtFormatted,
                    'promotion_coupon_id'     => null,
                    'notes'                   => null,
                    'customer_ip'             => null,
                    // Mollie plugin required fields
                    'abandoned_email'         => 0,
                    'recurring_sequence_index'=> null,
                    'qr_code'                 => null,
                    'mollie_payment_id'       => null,
                ]);

                $orderId = (int) $conn->lastInsertId();

                // Insert Payment
                if ($paymentMethodId) {
                    $conn->insert('sylius_payment', [
                        'order_id'      => $orderId,
                        'method_id'     => $paymentMethodId,
                        'amount'        => $total,
                        'currency_code' => $currencyCode,
                        'state'         => $paymentState,
                        'details'       => '[]',
                        'created_at'    => $createdAtFormatted,
                        'updated_at'    => $createdAtFormatted,
                    ]);
                }

                // Insert Shipment
                if ($shippingMethodId) {
                    $conn->insert('sylius_shipment', [
                        'order_id'          => $orderId,
                        'method_id'         => $shippingMethodId,
                        'state'             => $shippingState,
                        'adjustments_total' => 0,
                        'created_at'        => $createdAtFormatted,
                        'updated_at'        => $createdAtFormatted,
                    ]);
                }

                $importedCount++;
                $processedNumbers[] = $number;
            } catch (\Exception $e) {
                $io->warning(sprintf('Order #%s failed: %s', $number, $e->getMessage()));
                $failedCount++;
            }

            $i++;
            $io->progressAdvance();
        }

        fclose($handle);
        $io->progressFinish();

        if ($isDryRun) {
            $conn->rollBack();
            $io->warning('DRY-RUN: all changes have been rolled back. Nothing was saved.');
        }

        $io->section('Import Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['✅ Imported', $importedCount],
                ['⏭️  Skipped (duplicates / already exist)', $skippedCount],
                ['❌ Failed (invalid rows / errors)', $failedCount],
            ]
        );

        return Command::SUCCESS;
    }

    private function findOrCreateCustomer(string $clientName): CustomerInterface
    {
        $parts     = explode(' ', $clientName, 2);
        $firstName = trim($parts[0] ?? 'Guest');
        $lastName  = trim($parts[1] ?? 'Unknown');

        $customer = $this->customerRepository->findOneBy([
            'firstName' => $firstName,
            'lastName'  => $lastName,
        ]);

        if ($customer !== null) {
            return $customer;
        }

        /** @var CustomerInterface $customer */
        $customer = $this->customerFactory->createNew();
        $customer->setEmail(sprintf('guest_%s@cateco.test', substr(md5($clientName . microtime()), 0, 10)));
        $customer->setFirstName($firstName);
        $customer->setLastName($lastName);
        $customer->setCreatedAt(new \DateTime());

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function createDefaultAddress(CustomerInterface $customer): Address
    {
        $address = new Address();
        $address->setFirstName($customer->getFirstName() ?? 'Inconnu');
        $address->setLastName($customer->getLastName() ?? 'Inconnu');
        $address->setStreet(self::DEFAULT_STREET);
        $address->setCity(self::DEFAULT_CITY);
        $address->setPostcode(self::DEFAULT_POSTCODE);
        $address->setCountryCode(self::DEFAULT_COUNTRY_CODE);
        $address->setCreatedAt(new \DateTime());
        $address->setUpdatedAt(new \DateTime());

        return $address;
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
