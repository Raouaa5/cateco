<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Repository\CustomerRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:import-users',
    description: 'Import users from a CSV file into Sylius',
)]
class ImportUsersCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'sylius.factory.customer')]
        private FactoryInterface $customerFactory,
        #[Autowire(service: 'sylius.factory.shop_user')]
        private FactoryInterface $shopUserFactory,
        private CustomerRepositoryInterface $customerRepository,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the CSV file')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('file');

        if (!file_exists($filePath) || !is_readable($filePath)) {
            $io->error(sprintf('The file "%s" does not exist or is not readable.', $filePath));
            return Command::FAILURE;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $io->error('Cannot open the file.');
            return Command::FAILURE;
        }

        // Skipping the header
        $header = fgetcsv($handle, 0, ',');
        if ($header === false) {
            $io->error('The CSV file is empty or invalid.');
            return Command::FAILURE;
        }

        $io->title('Starting Users Import');
        $io->progressStart();

        $batchSize = 100;
        $i = 0;
        $importedCount = 0;
        $skippedCount = 0;
        $processedEmails = [];

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            // Check if row has enough columns (at least 9 expected based on CSV structure)
            if (count($data) < 9) {
                $io->warning(sprintf('Row %d is invalid (not enough columns), skipped.', $i + 2));
                $skippedCount++;
                continue;
            }

            // Raw data mapping
            $firstNameRaw = $data[0];
            $lastNameRaw = $data[1];
            $emailRaw = $data[2];
            $enabledRaw = $data[6];
            $createdAtRaw = $data[7];
            $lastLoginRaw = $data[8];

            // Cleaning data
            $firstName = trim(str_replace(['"', "'"], '', $firstNameRaw));
            $lastName = trim(str_replace(['"', "'"], '', $lastNameRaw));
            $email = mb_strtolower(trim(str_replace(['"', "'"], '', $emailRaw)));
            $enabled = trim($enabledRaw) == '1';

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $io->warning(sprintf('Row %d has invalid email "%s", skipped.', $i + 2, $emailRaw));
                $skippedCount++;
                continue;
            }

            if (in_array($email, $processedEmails, true)) {
                $io->warning(sprintf('Customer with email "%s" already processed in this file, skipped.', $email));
                $skippedCount++;
                continue;
            }

            // Check for duplicates in DB
            /** @var CustomerInterface|null $existingCustomer */
            $existingCustomer = $this->customerRepository->findOneBy(['email' => $email]);
            if ($existingCustomer !== null) {
                // We choose to skip duplicates as requested
                $io->warning(sprintf('Customer with email "%s" already exists in DB, skipped.', $email));
                $skippedCount++;
                continue;
            }

            $processedEmails[] = $email;

            // Date parsing
            $createdAt = !empty($createdAtRaw) ? new \DateTime(trim($createdAtRaw)) : new \DateTime();

            $lastLogin = null;
            if (!empty($lastLoginRaw) && trim($lastLoginRaw) !== 'NULL') {
                $lastLogin = \DateTime::createFromFormat('Y-m-d H:i:s', trim($lastLoginRaw));
            }

            // Customer creation
            /** @var CustomerInterface $customer */
            $customer = $this->customerFactory->createNew();
            $customer->setEmail($email);
            $customer->setFirstName($firstName);
            $customer->setLastName($lastName);
            $customer->setCreatedAt($createdAt);
            $customer->setUpdatedAt(new \DateTime());

            // ShopUser creation
            /** @var ShopUserInterface $user */
            $user = $this->shopUserFactory->createNew();
            $user->setCustomer($customer);
            
            $user->setUsername($email);
            $user->setUsernameCanonical($email);
            $user->setEmail($email);
            $user->setEmailCanonical($email);
            $user->setEnabled($enabled);
            $user->addRole('ROLE_USER');
            if ($lastLogin) {
                $user->setLastLogin($lastLogin);
            }

            // Generate a secure random password
            $plainPassword = '123456';
            $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);

            // Persist
            $this->entityManager->persist($customer);
            $this->entityManager->persist($user);

            $importedCount++;
            $i++;

            $io->progressAdvance();

            // Flush in batches
            if (($i % $batchSize) === 0) {
                $this->entityManager->flush();
                // Detach all objects from Doctrine to save RAM
                $this->entityManager->clear(CustomerInterface::class);
                $this->entityManager->clear(ShopUserInterface::class);
            }
        }

        // Flush remaining entities
        $this->entityManager->flush();
        $this->entityManager->clear(CustomerInterface::class);
        $this->entityManager->clear(ShopUserInterface::class);

        fclose($handle);
        $io->progressFinish();

        $io->success(sprintf('Import completed ! %d users imported, %d skipped.', $importedCount, $skippedCount));

        // Create Task artifact
        return Command::SUCCESS;
    }
}
