<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\OdooJsonRpcClient;
use Psr\Log\LoggerInterface;
use Sylius\Component\Customer\Model\CustomerInterface;

class UserRegisteredListener
{
    private const LOG_DIR = __DIR__ . '/../../var/log/';

    public function __construct(
        private readonly OdooJsonRpcClient $odoo,
        private readonly LoggerInterface   $logger,
    ) {}

    public function onUserRegister(object $event): void
    {
        $subject = method_exists($event, 'getSubject') ? $event->getSubject() : null;

        if (!$subject instanceof CustomerInterface) {
            $this->log('odoo_error', 'Skipped: subject is not a CustomerInterface. Got: ' . get_class($subject ?? new \stdClass()));
            return;
        }

        $customer = $subject;

        $firstName = (string) ($customer->getFirstName() ?? '');
        $lastName  = (string) ($customer->getLastName()  ?? '');
        $email     = (string) ($customer->getEmail()     ?? '');
        $phone     = (string) ($customer->getPhoneNumber() ?? ''); // Attempt to extract phone if supported

        if ($email === '') {
            $this->log('odoo_error', 'Skipped: customer has no email.');
            return;
        }

        $name = trim($firstName . ' ' . $lastName);

        try {
            $partnerId = $this->odoo->createOrUpdatePartner($name, $email, $phone !== '' ? $phone : null);

            $this->log('odoo', "SUCCESS | partner_id={$partnerId} | {$name} <{$email}>");
            $this->logger->info("[Odoo] Partner #{$partnerId} synced for {$email}");
        } catch (\Throwable $e) {
            // Never break the registration flow
            $this->log('odoo_error', 'ERROR | ' . $e->getMessage() . " | {$name} <{$email}>");
            $this->logger->error('[Odoo] Failed to sync partner: ' . $e->getMessage());
        }
    }

    private function log(string $file, string $message): void
    {
        $dir = self::LOG_DIR;

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf("[%s] %s\n", date('c'), $message);
        @file_put_contents($dir . $file . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}