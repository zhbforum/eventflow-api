<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Fixtures\OutboxCrashDatabase;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\TestCase;

#[SkipDatabaseRollback]
final class OutboxCrashRecoveryIntegrationTest extends TestCase
{
    public function testPublisherTransactionRollsBackAfterSigkillAndCanBeRetried(): void
    {
        if (
            PHP_OS_FAMILY !== 'Linux'
            || !extension_loaded('pcntl')
            || !function_exists('proc_open')
            || !function_exists('proc_terminate')
        ) {
            self::markTestSkipped(
                'Requires Linux and process control.',
            );
        }

        $connection = OutboxCrashDatabase::connect();

        $stateFile = tempnam(
            sys_get_temp_dir(),
            'eventflow_crash_',
        );

        self::assertIsString($stateFile);

        $state = null;
        $crashProcess = null;
        $recoveryProcess = null;

        try {
            self::assertSame(
                0,
                (int) $connection->fetchOne(
                    'SELECT COUNT(*)
                     FROM outbox_messages
                     WHERE published_at IS NULL',
                ),
                'Test database must have no pending outbox messages.',
            );

            $crashProcess = $this->startProbe(
                'crash',
                $stateFile,
            );

            $this->waitForFile(
                $stateFile . '.entered',
                $crashProcess,
                $stateFile,
            );

            $state = json_decode(
                (string) file_get_contents($stateFile),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            self::assertTrue(
                proc_get_status($crashProcess)['running'],
            );

            self::assertTrue(
                proc_terminate($crashProcess, SIGKILL),
            );

            $status = $this->waitForExit(
                $crashProcess,
                $stateFile,
            );

            self::assertTrue($status['signaled']);
            self::assertSame(SIGKILL, $status['termsig']);

            $outboxId = $state['outbox_id'];

            $row = $connection->fetchAssociative(
                'SELECT published_at, publish_attempts, last_error
                 FROM outbox_messages
                 WHERE id = :id',
                ['id' => $outboxId],
            );

            self::assertIsArray($row);
            self::assertNull($row['published_at']);

            self::assertSame(
                0,
                (int) $row['publish_attempts'],
            );

            self::assertNull($row['last_error']);

            $this->waitForRowUnlock(
                $connection,
                $outboxId,
            );

            $recoveryProcess = $this->startProbe(
                'recover',
                $stateFile,
            );

            $this->waitForFile(
                $stateFile . '.result',
                $recoveryProcess,
                $stateFile,
            );

            $recoveryStatus = $this->waitForExit(
                $recoveryProcess,
                $stateFile,
            );

            self::assertSame(
                0,
                $recoveryStatus['exitcode'],
            );

            $result = json_decode(
                (string) file_get_contents(
                    $stateFile . '.result',
                ),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            self::assertSame(
                [
                    'processed' => 1,
                    'published' => 1,
                    'failed' => 0,
                ],
                $result['result'],
            );

            self::assertSame(
                [$state['delivery_id']],
                $result['delivery_ids'],
            );

            $row = $connection->fetchAssociative(
                'SELECT published_at, publish_attempts, last_error
                 FROM outbox_messages
                 WHERE id = :id',
                ['id' => $outboxId],
            );

            self::assertIsArray($row);
            self::assertNotNull($row['published_at']);

            self::assertSame(
                1,
                (int) $row['publish_attempts'],
            );

            self::assertNull($row['last_error']);
        } finally {
            $this->stopProcess($crashProcess);
            $this->stopProcess($recoveryProcess);

            if ($state === null) {
                $raw = (string) @file_get_contents($stateFile);
                $state = json_decode($raw, true);
            }

            if (is_array($state)) {
                foreach (
                    [
                        ['outbox_messages', 'outbox_id'],
                        ['webhook_deliveries', 'delivery_id'],
                        ['webhook_endpoints', 'endpoint_id'],
                        ['events', 'event_id'],
                    ] as [$table, $key]
                ) {
                    if (isset($state[$key])) {
                        $connection->executeStatement(
                            "DELETE FROM {$table} WHERE id = :id",
                            ['id' => $state[$key]],
                        );
                    }
                }
            }

            $connection->close();

            foreach (
                [
                    $stateFile,
                    $stateFile . '.entered',
                    $stateFile . '.result',
                    $stateFile . '.stdout',
                    $stateFile . '.stderr',
                ] as $file
            ) {
                @unlink($file);
            }
        }
    }

    /**
     * @return resource
     */
    private function startProbe(
        string $mode,
        string $stateFile,
    ) {
        $fixture = dirname(__DIR__)
            . '/Fixtures/outbox-crash-probe.php';

        $process = proc_open(
            [
                PHP_BINARY,
                $fixture,
                $mode,
                $stateFile,
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $stateFile . '.stdout', 'w'],
                2 => ['file', $stateFile . '.stderr', 'w'],
            ],
            $pipes,
        );

        self::assertIsResource($process);

        return $process;
    }

    /**
     * @param resource $process
     */
    private function waitForFile(
        string $path,
        $process,
        string $stateFile,
    ): void {
        for ($attempt = 0; $attempt < 200; ++$attempt) {
            clearstatcache(true, $path);

            if (is_file($path) && filesize($path) > 0) {
                return;
            }

            if (!proc_get_status($process)['running']) {
                break;
            }

            usleep(50_000);
        }

        self::fail(
            'Probe did not reach checkpoint. '
                . $this->probeDiagnostics($stateFile),
        );
    }

    /**
     * @param resource $process
     *
     * @return array<string, mixed>
     */
    private function waitForExit(
        $process,
        string $stateFile,
    ): array {
        for ($attempt = 0; $attempt < 200; ++$attempt) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                return $status;
            }

            usleep(50_000);
        }

        self::fail(
            'Probe did not exit. '
                . $this->probeDiagnostics($stateFile),
        );
    }

    private function waitForRowUnlock(
        Connection $connection,
        string $outboxId,
    ): void {
        $lastError = '';

        for ($attempt = 0; $attempt < 100; ++$attempt) {
            try {
                $found = $connection->fetchOne(
                    'SELECT 1
                     FROM outbox_messages
                     WHERE id = :id
                     FOR UPDATE NOWAIT',
                    ['id' => $outboxId],
                );

                self::assertSame(1, (int) $found);

                return;
            } catch (Exception $exception) {
                $lastError = $exception->getMessage();

                usleep(50_000);
            }
        }

        self::fail(
            'Row lock not released: ' . $lastError,
        );
    }

    /**
     * @param resource|null $process
     */
    private function stopProcess($process): void
    {
        if (!is_resource($process)) {
            return;
        }

        if (proc_get_status($process)['running']) {
            proc_terminate($process, SIGKILL);
        }

        proc_close($process);
    }

    private function probeDiagnostics(
        string $stateFile,
    ): string {
        return sprintf(
            "State: %s\nSTDOUT:\n%s\nSTDERR:\n%s",
            (string) @file_get_contents($stateFile),
            (string) @file_get_contents(
                $stateFile . '.stdout',
            ),
            (string) @file_get_contents(
                $stateFile . '.stderr',
            ),
        );
    }
}
