<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Throwable;

final readonly class DatabaseReadinessChecker
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function isReady(): bool
    {
        try {
            return (string) $this->connection->fetchOne(
                'SELECT 1',
            ) === '1';
        } catch (Throwable) {
            return false;
        }
    }
}
