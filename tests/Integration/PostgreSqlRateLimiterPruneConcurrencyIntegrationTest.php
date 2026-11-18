<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\PostgreSqlRateLimiter;
use App\Tests\Fixtures\OutboxCrashDatabase;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use PHPUnit\Framework\TestCase;

#[SkipDatabaseRollback]
final class PostgreSqlRateLimiterPruneConcurrencyIntegrationTest extends TestCase
{
    public function testCleanupSkipsLockedAndPreservesRefreshedBucket(): void
    {
        $writer = OutboxCrashDatabase::connect();
        $cleaner = OutboxCrashDatabase::connect();

        $lockedKey = hash('sha256', random_bytes(32));
        $unlockedKey = hash('sha256', random_bytes(32));

        $writerLimiter = new PostgreSqlRateLimiter($writer);
        $cleanerLimiter = new PostgreSqlRateLimiter($cleaner);

        try {
            self::assertTrue(
                $writerLimiter->consume($lockedKey, 1)->allowed,
            );

            self::assertTrue(
                $writerLimiter->consume($unlockedKey, 1)->allowed,
            );

            $writer->executeStatement(
                <<<'SQL'
                    UPDATE api_rate_limit_buckets
                    SET reset_at = clock_timestamp()
                        - INTERVAL '11 minutes'
                    WHERE bucket_key IN (:locked_key, :unlocked_key)
                    SQL,
                [
                    'locked_key' => $lockedKey,
                    'unlocked_key' => $unlockedKey,
                ],
            );

            $writer->beginTransaction();

            self::assertSame(
                $lockedKey,
                $writer->fetchOne(
                    <<<'SQL'
                        SELECT bucket_key
                        FROM api_rate_limit_buckets
                        WHERE bucket_key = :key
                        FOR UPDATE
                        SQL,
                    ['key' => $lockedKey],
                ),
            );

            $cleaner->executeStatement(
                "SET statement_timeout = '1500ms'",
            );

            self::assertGreaterThanOrEqual(
                1,
                $cleanerLimiter->purgeExpired(1000),
            );

            self::assertSame(
                $lockedKey,
                $cleaner->fetchOne(
                    <<<'SQL'
                        SELECT bucket_key
                        FROM api_rate_limit_buckets
                        WHERE bucket_key = :key
                        SQL,
                    ['key' => $lockedKey],
                ),
            );

            self::assertFalse(
                $cleaner->fetchOne(
                    <<<'SQL'
                        SELECT bucket_key
                        FROM api_rate_limit_buckets
                        WHERE bucket_key = :key
                        SQL,
                    ['key' => $unlockedKey],
                ),
            );

            $refreshed = $writerLimiter->consume(
                $lockedKey,
                1,
            );

            self::assertTrue($refreshed->allowed);
            self::assertSame(0, $refreshed->remaining);

            $writer->commit();

            $cleanerLimiter->purgeExpired(1000);

            self::assertSame(
                $lockedKey,
                $cleaner->fetchOne(
                    <<<'SQL'
                        SELECT bucket_key
                        FROM api_rate_limit_buckets
                        WHERE bucket_key = :key
                        SQL,
                    ['key' => $lockedKey],
                ),
            );

            self::assertFalse(
                $writerLimiter->consume(
                    $lockedKey,
                    1,
                )->allowed,
            );
        } finally {
            if ($writer->isTransactionActive()) {
                $writer->rollBack();
            }

            $writer->executeStatement(
                <<<'SQL'
                    DELETE FROM api_rate_limit_buckets
                    WHERE bucket_key IN (:locked_key, :unlocked_key)
                    SQL,
                [
                    'locked_key' => $lockedKey,
                    'unlocked_key' => $unlockedKey,
                ],
            );

            $writer->close();
            $cleaner->close();
        }
    }
}
