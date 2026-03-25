<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260325191500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configure Mollie Payment Method automatically with test keys';
    }

    public function up(Schema $schema): void
    {
        // Give the gateway config a random ID and insert it
        $this->addSql("
            INSERT INTO sylius_gateway_config (gateway_name, factory_name, config) 
            VALUES (
                'Mollie', 
                'mollie', 
                '{\"api_key\": \"test_MmBxMA6EdQm8fTRJzbdBJjwfAKrmtm\", \"profile_id\": \"pfl_mpK95xbf4e\", \"environment\": \"sandbox\", \"loggerLevel\": \"INFO\"}'
            )
        ");

        $this->addSql("SET @gateway_id = LAST_INSERT_ID()");

        // Create the payment method
        $this->addSql("
            INSERT INTO sylius_payment_method (gateway_config_id, code, environment, is_enabled, position, created_at, updated_at) 
            VALUES (
                @gateway_id, 
                'mollie', 
                'test', 
                1, 
                0, 
                NOW(), 
                NOW()
            )
        ");

        $this->addSql("SET @method_id = LAST_INSERT_ID()");

        // Add translations (English and French)
        $this->addSql("
            INSERT INTO sylius_payment_method_translation (translatable_id, name, description, instructions, locale) 
            VALUES 
            (@method_id, 'Mollie (Test)', 'Pay with Mollie', null, 'en_US'),
            (@method_id, 'Mollie (Test)', 'Payer avec Mollie', null, 'fr_FR')
        ");

        // Link the payment method to all channels
        $this->addSql("
            INSERT INTO sylius_payment_method_channels (payment_method_id, channel_id)
            SELECT @method_id, id FROM sylius_channel
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sylius_payment_method_channels WHERE payment_method_id IN (SELECT id FROM sylius_payment_method WHERE code = 'mollie')");
        $this->addSql("DELETE FROM sylius_payment_method_translation WHERE translatable_id IN (SELECT id FROM sylius_payment_method WHERE code = 'mollie')");
        $this->addSql("DELETE FROM sylius_gateway_config WHERE id IN (SELECT gateway_config_id FROM sylius_payment_method WHERE code = 'mollie')");
        $this->addSql("DELETE FROM sylius_payment_method WHERE code = 'mollie'");
    }
}
