<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add event idempotency hashes and unique constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE events
             ADD idempotency_key_hash VARCHAR(64) DEFAULT NULL',
        );

        $this->addSql(
            'ALTER TABLE events
             ADD request_hash VARCHAR(64) DEFAULT NULL',
        );

        $this->addSql(
            'CREATE UNIQUE INDEX uniq_events_idempotency_key_hash
             ON events (idempotency_key_hash)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DROP INDEX uniq_events_idempotency_key_hash',
        );

        $this->addSql(
            'ALTER TABLE events DROP idempotency_key_hash',
        );

        $this->addSql(
            'ALTER TABLE events DROP request_hash',
        );
    }
}
