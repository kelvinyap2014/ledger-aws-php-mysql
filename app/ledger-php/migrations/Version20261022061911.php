<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261022061911 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE idempotency_keys (idempotency_key VARCHAR(100) NOT NULL, request_hash VARCHAR(64) NOT NULL, response_body LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (idempotency_key)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ledger_entries (id VARCHAR(36) NOT NULL, transaction_reference VARCHAR(50) NOT NULL, account_id VARCHAR(36) NOT NULL, entry_type VARCHAR(6) NOT NULL, amount NUMERIC(19, 4) NOT NULL, currency VARCHAR(3) NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE idempotency_keys');
        $this->addSql('DROP TABLE ledger_entries');
    }
}
