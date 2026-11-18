<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Fixtures\OutboxCrashDatabase;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use PHPUnit\Framework\TestCase;

#[SkipDatabaseRollback]
final class PostgreSqlRateLimiterConcurrencyIntegrationTest extends TestCase
{
    public function testConcurrentRequestsRespectSharedLimit(): void
    {
        if (
            PHP_OS_FAMILY !== 'Linux'
            || !function_exists('proc_open')
            || !function_exists('proc_terminate')
        ) {
            self::markTestSkipped(
                'Requires Linux and process control.',
            );
        }

        $connection = OutboxCrashDatabase::connect();

        $key = hash('sha256', random_bytes(32));
        $limit = 5;
        $workers = 12;

        $directory = sys_get_temp_dir()
            . '/eventflow-rate-limit-'
            . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($directory, 0700));

        $processes = [];

        try {
            $fixture = dirname(__DIR__)
                . '/Fixtures/rate-limit-concurrency-probe.php';

            for ($i = 0; $i < $workers; ++$i) {
                $process = proc_open(
                    [
                        PHP_BINARY,
                        $fixture,
                        $key,
                        (string) $limit,
                        $directory,
                        (string) $i,
                    ],
                    [
                        0 => ['file', '/dev/null', 'r'],
                        1 => [
                            'file',
                            $directory . '/stdout-' . $i,
                            'w',
                        ],
                        2 => [
                            'file',
                            $directory . '/stderr-' . $i,
                            'w',
                        ],
                    ],
                    $pipes,
                );

                self::assertIsResource($process);

                $processes[] = $process;
            }

            $ready = [];

            for ($attempt = 0; $attempt < 400; ++$attempt) {
                $ready = glob($directory . '/ready-*') ?: [];

                if (count($ready) === $workers) {
                    break;
                }

                usleep(50_000);
            }

            self::assertCount(
                $workers,
                $ready,
                'Not all workers reached the start barrier.',
            );

            self::assertSame(
                1,
                file_put_contents($directory . '/start', '1'),
            );

            $results = [];

            for ($attempt = 0; $attempt < 400; ++$attempt) {
                $results = glob($directory . '/result-*') ?: [];

                if (count($results) === $workers) {
                    break;
                }

                usleep(50_000);
            }

            self::assertCount(
                $workers,
                $results,
                'Not all workers completed. Check stderr files.',
            );

            $allowed = 0;
            $blocked = 0;

            foreach ($results as $file) {
                $result = trim(
                    (string) file_get_contents($file),
                );

                if ($result === 'allowed') {
                    ++$allowed;
                } elseif ($result === 'blocked') {
                    ++$blocked;
                } else {
                    self::fail('Unexpected probe result.');
                }
            }

            self::assertSame(5, $allowed);
            self::assertSame(7, $blocked);

            $count = $connection->fetchOne(
                'SELECT hit_count
                 FROM api_rate_limit_buckets
                 WHERE bucket_key = :key',
                ['key' => $key],
            );

            self::assertSame(6, (int) $count);

            foreach ($processes as $process) {
                self::assertSame(0, proc_close($process));
            }

            $processes = [];
        } finally {
            foreach ($processes as $process) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }

                proc_close($process);
            }

            $connection->executeStatement(
                'DELETE FROM api_rate_limit_buckets
                 WHERE bucket_key = :key',
                ['key' => $key],
            );

            $connection->close();

            foreach (glob($directory . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }
    }
}
