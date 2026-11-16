<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Tests\Fixtures\OutboxCrashDatabase;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use PHPUnit\Framework\TestCase;

#[SkipDatabaseRollback]
final class DeliveryReplayConcurrencyIntegrationTest extends TestCase
{
    public function testConcurrentReplayCreatesOneGeneration(): void
    {
        if (
            PHP_OS_FAMILY !== 'Linux'
            || !function_exists('proc_open')
            || !function_exists('proc_terminate')
        ) {
            self::markTestSkipped('Requires Linux process control.');
        }

        $entityManager = OutboxCrashDatabase::entityManager();
        $connection = $entityManager->getConnection();

        $prefix = tempnam(
            sys_get_temp_dir(),
            'eventflow_replay_race_',
        );

        self::assertIsString($prefix);

        $processes = [];
        $ids = [];
        $lockHeld = false;

        try {
            $event = new Event(
                'order.created',
                'replay-concurrency-test',
                ['orderId' => 'ORD-REPLAY-RACE'],
            );

            $endpoint = new WebhookEndpoint(
                'https://example.com/race',
                ['order.created'],
            );

            $delivery = new WebhookDelivery($event, $endpoint);
            $delivery->startAttempt();
            $delivery->markFailed('Webhook returned HTTP 503');

            $outbox = new OutboxMessage($delivery);

            $ids = [
                'event' => $event->getId(),
                'endpoint' => $endpoint->getId(),
                'delivery' => $delivery->getId(),
            ];

            foreach (
                [$event, $endpoint, $delivery, $outbox] as $record
            ) {
                $entityManager->persist($record);
            }

            $entityManager->flush();

            // Hold the same advisory lock used by
            // DeliveryReplayService and the webhook worker.
            $connection->fetchOne(
                'SELECT pg_advisory_lock(
                    hashtextextended(CAST(:id AS text), 0)
                )',
                ['id' => $ids['delivery']],
            );

            $lockHeld = true;

            // Both processes will attempt replay while
            // the parent still owns the PostgreSQL lock.
            foreach (['a', 'b'] as $worker) {
                $processes[$worker] = self::startProbe(
                    $ids['delivery'],
                    $prefix,
                    $worker,
                );
            }

            foreach (['a', 'b'] as $worker) {
                self::waitForMarker(
                    $prefix . '.' . $worker . '.ready',
                    $processes[$worker],
                    $prefix,
                    $worker,
                );
            }

            usleep(200_000);

            $connection->fetchOne(
                'SELECT pg_advisory_unlock(
                    hashtextextended(CAST(:id AS text), 0)
                )',
                ['id' => $ids['delivery']],
            );

            $lockHeld = false;

            $results = [];

            foreach (['a', 'b'] as $worker) {
                $resultPath = $prefix . '.' . $worker . '.result';

                self::waitForMarker(
                    $resultPath,
                    $processes[$worker],
                    $prefix,
                    $worker,
                );

                $results[$worker] = json_decode(
                    (string) file_get_contents($resultPath),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
            }

            $outcomes = [
                $results['a']['outcome'],
                $results['b']['outcome'],
            ];

            sort($outcomes);

            self::assertSame(
                ['accepted', 'conflict'],
                $outcomes,
            );

            $row = $connection->fetchAssociative(
                'SELECT status, attempts, replay_generation
                 FROM webhook_deliveries
                 WHERE id = :id',
                ['id' => $ids['delivery']],
            );

            self::assertIsArray($row);
            self::assertSame('pending', $row['status']);
            self::assertSame(1, (int) $row['attempts']);
            self::assertSame(1, (int) $row['replay_generation']);

            $outboxRows = $connection->fetchFirstColumn(
                'SELECT replay_generation
                 FROM outbox_messages
                 WHERE delivery_id = :id
                 ORDER BY replay_generation',
                ['id' => $ids['delivery']],
            );

            self::assertSame(
                [0, 1],
                array_map('intval', $outboxRows),
            );

            $auditRows = $connection->fetchAllAssociative(
                'SELECT replay_generation, requested_by,
                        previous_error
                 FROM webhook_delivery_replays
                 WHERE delivery_id = :id',
                ['id' => $ids['delivery']],
            );

            self::assertCount(1, $auditRows);

            self::assertSame(
                1,
                (int) $auditRows[0]['replay_generation'],
            );

            self::assertContains(
                $auditRows[0]['requested_by'],
                ['race-a', 'race-b'],
            );

            self::assertSame(
                'Webhook returned HTTP 503',
                $auditRows[0]['previous_error'],
            );
        } finally {
            if ($lockHeld && isset($ids['delivery'])) {
                $connection->fetchOne(
                    'SELECT pg_advisory_unlock(
                        hashtextextended(CAST(:id AS text), 0)
                    )',
                    ['id' => $ids['delivery']],
                );
            }

            foreach ($processes as $process) {
                if (is_resource($process)) {
                    if (proc_get_status($process)['running']) {
                        proc_terminate($process, SIGKILL);
                    }

                    proc_close($process);
                }
            }

            // This test intentionally bypasses DAMA rollback
            // to allow independent processes to see committed data.
            if ($ids !== []) {
                $connection->executeStatement(
                    'DELETE FROM webhook_delivery_replays
                     WHERE delivery_id = :id',
                    ['id' => $ids['delivery']],
                );

                $connection->executeStatement(
                    'DELETE FROM outbox_messages
                     WHERE delivery_id = :id',
                    ['id' => $ids['delivery']],
                );

                $connection->executeStatement(
                    'DELETE FROM webhook_deliveries
                     WHERE id = :id',
                    ['id' => $ids['delivery']],
                );

                $connection->executeStatement(
                    'DELETE FROM webhook_endpoints
                     WHERE id = :id',
                    ['id' => $ids['endpoint']],
                );

                $connection->executeStatement(
                    'DELETE FROM events WHERE id = :id',
                    ['id' => $ids['event']],
                );
            }

            $connection->close();

            foreach (['a', 'b'] as $worker) {
                foreach (
                    ['ready', 'result', 'stdout', 'stderr'] as $suffix
                ) {
                    @unlink(
                        $prefix . '.' . $worker . '.' . $suffix,
                    );
                }
            }

            @unlink($prefix);
        }
    }

    /**
     * @return resource
     */
    private static function startProbe(
        string $deliveryId,
        string $prefix,
        string $worker,
    ) {
        $fixture = dirname(__DIR__)
            . '/Fixtures/replay-concurrency-probe.php';

        $process = proc_open(
            [
                PHP_BINARY,
                $fixture,
                $deliveryId,
                $prefix,
                $worker,
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => [
                    'file',
                    $prefix . '.' . $worker . '.stdout',
                    'w',
                ],
                2 => [
                    'file',
                    $prefix . '.' . $worker . '.stderr',
                    'w',
                ],
            ],
            $pipes,
        );

        self::assertIsResource($process);

        return $process;
    }

    /**
     * @param resource $process
     */
    private static function waitForMarker(
        string $path,
        $process,
        string $prefix,
        string $worker,
    ): void {
        for ($attempt = 0; $attempt < 300; ++$attempt) {
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
            'Replay process did not reach checkpoint: '
            . $worker
            . "\nSTDOUT:\n"
            . (string) @file_get_contents(
                $prefix . '.' . $worker . '.stdout',
            )
            . "\nSTDERR:\n"
            . (string) @file_get_contents(
                $prefix . '.' . $worker . '.stderr',
            ),
        );
    }
}
