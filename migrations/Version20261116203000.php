<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add webhook delivery replay generations and audit history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE webhook_deliveries
             ADD replay_generation INT DEFAULT 0 NOT NULL'
        );

        $this->addSql(
            'ALTER TABLE outbox_messages
             ADD replay_generation INT DEFAULT 0 NOT NULL'
        );

        $this->addSql(
            'DROP INDEX uniq_outbox_delivery'
        );

        $this->addSql(
            'CREATE UNIQUE INDEX uniq_outbox_delivery_generation
             ON outbox_messages (delivery_id, replay_generation)'
        );

        $this->addSql(
            'CREATE TABLE webhook_delivery_replays (
                id VARCHAR(36) NOT NULL,
                delivery_id VARCHAR(36) NOT NULL,
                replay_generation INT NOT NULL,
                requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                requested_by VARCHAR(255) NOT NULL,
                previous_error TEXT DEFAULT NULL,
                PRIMARY KEY (id)
            )'
        );

        $this->addSql(
            'CREATE UNIQUE INDEX uniq_delivery_replay_generation
             ON webhook_delivery_replays (
                delivery_id,
                replay_generation
             )'
        );

        $this->addSql(
            'ALTER TABLE webhook_delivery_replays
             ADD CONSTRAINT fk_replay_delivery
             FOREIGN KEY (delivery_id)
             REFERENCES webhook_deliveries (id)
             NOT DEFERRABLE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE webhook_delivery_replays
             DROP CONSTRAINT fk_replay_delivery'
        );

        $this->addSql(
            'DROP TABLE webhook_delivery_replays'
        );

        $this->addSql(
            'DROP INDEX uniq_outbox_delivery_generation'
        );

        $this->addSql(
            'CREATE UNIQUE INDEX uniq_outbox_delivery
             ON outbox_messages (delivery_id)'
        );

        $this->addSql(
            'ALTER TABLE outbox_messages
             DROP replay_generation'
        );

        $this->addSql(
            'ALTER TABLE webhook_deliveries
             DROP replay_generation'
        );
    }
}
