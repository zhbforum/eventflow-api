<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110182628 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE outbox_messages (id VARCHAR(36) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, publish_attempts INT NOT NULL, last_error TEXT DEFAULT NULL, delivery_id VARCHAR(36) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_outbox_delivery ON outbox_messages (delivery_id)');
        $this->addSql('ALTER TABLE outbox_messages ADD CONSTRAINT FK_24F454FE12136921 FOREIGN KEY (delivery_id) REFERENCES webhook_deliveries (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE outbox_messages DROP CONSTRAINT FK_24F454FE12136921');
        $this->addSql('DROP TABLE outbox_messages');
    }
}
