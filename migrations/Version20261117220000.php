<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed legacy organization for existing data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO organizations (
                id,
                slug,
                name,
                created_at
            ) VALUES (
                '019a8d00-0000-7000-8000-000000000001',
                'legacy',
                'Legacy Organization',
                NOW() AT TIME ZONE 'UTC'
            )",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE FROM organizations
             WHERE id = '019a8d00-0000-7000-8000-000000000001'
               AND slug = 'legacy'",
        );
    }
}
