<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260417000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cateco_wishlist_item table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE IF NOT EXISTS cateco_wishlist_item (
                id          INT AUTO_INCREMENT NOT NULL,
                customer_id INT NOT NULL,
                product_id  INT NOT NULL,
                created_at  DATETIME NOT NULL,
                UNIQUE INDEX unique_customer_product (customer_id, product_id),
                INDEX IDX_WISHLIST_CUSTOMER (customer_id),
                INDEX IDX_WISHLIST_PRODUCT  (product_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            ALTER TABLE cateco_wishlist_item
                ADD CONSTRAINT FK_WISHLIST_CUSTOMER
                    FOREIGN KEY (customer_id) REFERENCES sylius_customer (id) ON DELETE CASCADE,
                ADD CONSTRAINT FK_WISHLIST_PRODUCT
                    FOREIGN KEY (product_id) REFERENCES sylius_product (id) ON DELETE CASCADE
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cateco_wishlist_item DROP FOREIGN KEY FK_WISHLIST_CUSTOMER');
        $this->addSql('ALTER TABLE cateco_wishlist_item DROP FOREIGN KEY FK_WISHLIST_PRODUCT');
        $this->addSql('DROP TABLE IF EXISTS cateco_wishlist_item');
    }
}
