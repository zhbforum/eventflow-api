<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\PostgreSqlRateLimiter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PostgreSqlRateLimiterIntegrationTest extends KernelTestCase
{
    public function testRequestsAreLimitedWithinOneWindow(): void
    {
        self::bootKernel();

        $limiter = self::limiter();
        $key = self::key();

        $first = $limiter->consume($key, 2);
        $second = $limiter->consume($key, 2);
        $third = $limiter->consume($key, 2);

        self::assertTrue($first->allowed);
        self::assertSame(1, $first->remaining);
        self::assertNull($first->retryAfter);

        self::assertTrue($second->allowed);
        self::assertSame(0, $second->remaining);

        self::assertFalse($third->allowed);
        self::assertSame(0, $third->remaining);
        self::assertNotNull($third->retryAfter);
        self::assertGreaterThanOrEqual(1, $third->retryAfter);
        self::assertLessThanOrEqual(60, $third->retryAfter);
    }

    public function testDifferentKeysHaveIndependentLimits(): void
    {
        self::bootKernel();

        $limiter = self::limiter();
        $firstKey = self::key();
        $secondKey = self::key();

        self::assertTrue(
            $limiter->consume($firstKey, 1)->allowed,
        );

        self::assertFalse(
            $limiter->consume($firstKey, 1)->allowed,
        );

        self::assertTrue(
            $limiter->consume($secondKey, 1)->allowed,
        );
    }

    public function testExpiredWindowResetsCounter(): void
    {
        self::bootKernel();

        $limiter = self::limiter();
        $key = self::key();

        self::assertTrue($limiter->consume($key, 1)->allowed);
        self::assertFalse($limiter->consume($key, 1)->allowed);

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE api_rate_limit_buckets
                SET reset_at = clock_timestamp() - INTERVAL '1 second'
                WHERE bucket_key = :key
                SQL,
            ['key' => $key],
        );

        $result = $limiter->consume($key, 1);

        self::assertTrue($result->allowed);
        self::assertSame(0, $result->remaining);
        self::assertNull($result->retryAfter);
    }

    public function testExpiredCountersCanBePurged(): void
    {
        self::bootKernel();

        $limiter = self::limiter();
        $expiredKey = self::key();
        $activeKey = self::key();

        $limiter->consume($expiredKey, 1);
        $limiter->consume($activeKey, 1);

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $connection = $entityManager->getConnection();

        $connection->executeStatement(
            <<<'SQL'
                UPDATE api_rate_limit_buckets
                SET reset_at = clock_timestamp() - INTERVAL '11 minutes'
                WHERE bucket_key = :key
                SQL,
            ['key' => $expiredKey],
        );

        self::assertSame(1, $limiter->purgeExpired());

        self::assertFalse(
            $connection->fetchOne(
                'SELECT bucket_key
                 FROM api_rate_limit_buckets
                 WHERE bucket_key = :key',
                ['key' => $expiredKey],
            ),
        );

        self::assertSame(
            $activeKey,
            $connection->fetchOne(
                'SELECT bucket_key
                 FROM api_rate_limit_buckets
                 WHERE bucket_key = :key',
                ['key' => $activeKey],
            ),
        );
    }

    private static function limiter(): PostgreSqlRateLimiter
    {
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        return new PostgreSqlRateLimiter(
            $entityManager->getConnection(),
        );
    }

    private static function key(): string
    {
        return hash('sha256', random_bytes(32));
    }
}
