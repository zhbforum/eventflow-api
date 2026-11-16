<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115225200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add HMAC signing secrets to webhook endpoints';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'ALTER TABLE webhook_endpoints
             ADD signing_secret VARCHAR(64) DEFAULT NULL'
        );

        $this->addSql(
            "UPDATE webhook_endpoints
             SET signing_secret =
                 replace(gen_random_uuid()::text, '-', '') ||
                 replace(gen_random_uuid()::text, '-', '')"
        );

        $this->addSql(
            'ALTER TABLE webhook_endpoints
             ALTER COLUMN signing_secret SET NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(
            'ALTER TABLE webhook_endpoints DROP signing_secret'
        );
    }
}
