<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Service\PostgreSqlRateLimiter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneRateLimitBucketsCommandTest extends KernelTestCase
{
    public function testCommandDeletesExpiredButNotActiveBuckets(): void
    {
        $kernel = self::bootKernel();

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $connection = $entityManager->getConnection();
        $limiter = new PostgreSqlRateLimiter($connection);

        $expiredKey = hash('sha256', random_bytes(32));
        $activeKey = hash('sha256', random_bytes(32));

        $limiter->consume($expiredKey, 10);
        $limiter->consume($activeKey, 10);

        $connection->executeStatement(
            <<<'SQL'
                UPDATE api_rate_limit_buckets
                SET reset_at = clock_timestamp()
                    - INTERVAL '100 years'
                WHERE bucket_key = :key
                SQL,
            ['key' => $expiredKey],
        );

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester(
            $application->find('app:rate-limit:prune'),
        );

        $exitCode = $tester->execute([
            '--batch-size' => '1000',
            '--max-batches' => '10',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        self::assertStringContainsString(
            'Purged ',
            $tester->getDisplay(),
        );

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

    public function testCommandRejectsInvalidBatchSize(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester(
            $application->find('app:rate-limit:prune'),
        );

        self::assertSame(
            Command::INVALID,
            $tester->execute([
                '--batch-size' => '0',
            ]),
        );
    }
}
