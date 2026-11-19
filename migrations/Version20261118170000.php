<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create shared API rate limit counters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE api_rate_limit_buckets (
                bucket_key VARCHAR(64) NOT NULL,
                hit_count INTEGER NOT NULL,
                reset_at TIMESTAMP WITH TIME ZONE NOT NULL,
                PRIMARY KEY (bucket_key),
                CONSTRAINT chk_api_rate_limit_hit_count
                    CHECK (hit_count >= 1)
            )',
        );

        $this->addSql(
            'CREATE INDEX idx_api_rate_limit_buckets_reset_at
             ON api_rate_limit_buckets (reset_at)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_rate_limit_buckets');
    }
}
