<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create organizations and organization memberships';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE organizations (
                id VARCHAR(36) NOT NULL,
                slug VARCHAR(63) NOT NULL,
                name VARCHAR(150) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )',
        );

        $this->addSql(
            'CREATE UNIQUE INDEX uniq_organizations_slug
             ON organizations (slug)',
        );

        $this->addSql(
            'CREATE TABLE organization_memberships (
                id VARCHAR(36) NOT NULL,
                organization_id VARCHAR(36) NOT NULL,
                oidc_subject VARCHAR(255) NOT NULL,
                role VARCHAR(10) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )',
        );

        $this->addSql(
            'CREATE UNIQUE INDEX uniq_org_memberships_org_subject
             ON organization_memberships (
                 organization_id,
                 oidc_subject
             )',
        );

        $this->addSql(
            'CREATE INDEX idx_org_memberships_subject
             ON organization_memberships (oidc_subject)',
        );

        $this->addSql(
            'ALTER TABLE organization_memberships
             ADD CONSTRAINT fk_org_memberships_organization
             FOREIGN KEY (organization_id)
             REFERENCES organizations (id)
             NOT DEFERRABLE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DROP TABLE organization_memberships',
        );

        $this->addSql(
            'DROP TABLE organizations',
        );
    }
}
