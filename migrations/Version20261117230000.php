<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assign events and webhook endpoints to organizations';
    }

    public function up(Schema $schema): void
    {
        $legacyId = '019a8d00-0000-7000-8000-000000000001';

        $this->addSql(
            "ALTER TABLE events
             ADD organization_id VARCHAR(36)
             DEFAULT '$legacyId' NOT NULL",
        );

        $this->addSql(
            "ALTER TABLE webhook_endpoints
             ADD organization_id VARCHAR(36)
             DEFAULT '$legacyId' NOT NULL",
        );

        $this->addSql(
            'CREATE INDEX idx_events_org_created
             ON events (organization_id, created_at)',
        );

        $this->addSql(
            'CREATE INDEX idx_webhook_endpoints_org_active
             ON webhook_endpoints (organization_id, is_active)',
        );

        $this->addSql(
            'ALTER TABLE events
             ADD CONSTRAINT fk_events_organization
             FOREIGN KEY (organization_id)
             REFERENCES organizations (id)
             NOT DEFERRABLE',
        );

        $this->addSql(
            'ALTER TABLE webhook_endpoints
             ADD CONSTRAINT fk_webhook_endpoints_organization
             FOREIGN KEY (organization_id)
             REFERENCES organizations (id)
             NOT DEFERRABLE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE events
             DROP CONSTRAINT fk_events_organization',
        );

        $this->addSql(
            'ALTER TABLE webhook_endpoints
             DROP CONSTRAINT fk_webhook_endpoints_organization',
        );

        $this->addSql(
            'DROP INDEX idx_events_org_created',
        );

        $this->addSql(
            'DROP INDEX idx_webhook_endpoints_org_active',
        );

        $this->addSql(
            'ALTER TABLE events DROP organization_id',
        );

        $this->addSql(
            'ALTER TABLE webhook_endpoints DROP organization_id',
        );
    }
}
