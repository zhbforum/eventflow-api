<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\WebhookEndpoint;
use App\Service\EventRequestFingerprint;
use App\Tests\Fixtures\OutboxCrashDatabase;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use PHPUnit\Framework\TestCase;

#[SkipDatabaseRollback]
final class EventIdempotencyConcurrencyIntegrationTest extends TestCase
{
    public function testConcurrentRequestsCreateOneEventAndOutbox(): void
    {
        if (
            PHP_OS_FAMILY !== 'Linux'
            || !function_exists('proc_open')
            || !function_exists('proc_terminate')
        ) {
            self::markTestSkipped('Requires Linux process control.');
        }

        $em = OutboxCrashDatabase::entityManager();
        $connection = $em->getConnection();

        $key = 'eventflow-race-' . bin2hex(random_bytes(12));
        $keyHash = EventRequestFingerprint::keyHash(
            'integration-probe',
            $key,
        );
        $type = 'idempotency.race.' . bin2hex(random_bytes(6));

        $prefix = tempnam(
            sys_get_temp_dir(),
            'eventflow_idem_race_',
        );

        self::assertIsString($prefix);

        $processes = [];
        $endpointId = null;
        $lockHeld = false;

        try {
            $endpoint = new WebhookEndpoint(
                'https://example.com/idempotency-race',
                [$type],
            );

            $endpointId = $endpoint->getId();

            $em->persist($endpoint);
            $em->flush();

            $connection->fetchOne(
                'SELECT pg_advisory_lock(
                    hashtextextended(CAST(:key AS text), 0)
                )',
                ['key' => 'incoming-event:' . $keyHash],
            );

            $lockHeld = true;

            foreach (['a', 'b'] as $worker) {
                $processes[$worker] = self::startProbe(
                    $key,
                    $type,
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

            $bothWaiting = false;

            for ($attempt = 0; $attempt < 200; ++$attempt) {
                $waiting = (int) $connection->fetchOne(
                    "SELECT COUNT(*)
                     FROM pg_stat_activity
                     WHERE datname = current_database()
                       AND application_name IN (:a, :b)
                       AND wait_event_type = 'Lock'
                       AND wait_event = 'advisory'",
                    [
                        'a' => 'eventflow-idem-a-'
                            . substr($keyHash, 0, 12),
                        'b' => 'eventflow-idem-b-'
                            . substr($keyHash, 0, 12),
                    ],
                );

                if ($waiting === 2) {
                    $bothWaiting = true;
                    break;
                }

                usleep(50_000);
            }

            self::assertTrue(
                $bothWaiting,
                'Both requests must wait on the same advisory lock.',
            );

            $connection->fetchOne(
                'SELECT pg_advisory_unlock(
                    hashtextextended(CAST(:key AS text), 0)
                )',
                ['key' => 'incoming-event:' . $keyHash],
            );

            $lockHeld = false;

            $results = [];

            foreach (['a', 'b'] as $worker) {
                $path = $prefix . '.' . $worker . '.result';

                self::waitForMarker(
                    $path,
                    $processes[$worker],
                    $prefix,
                    $worker,
                );

                $results[$worker] = json_decode(
                    (string) file_get_contents($path),
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
                ['created', 'replayed'],
                $outcomes,
                json_encode($results, JSON_THROW_ON_ERROR),
            );

            self::assertSame(
                $results['a']['eventId'],
                $results['b']['eventId'],
            );

            $eventId = $results['a']['eventId'];

            self::assertSame(
                1,
                (int) $connection->fetchOne(
                    'SELECT COUNT(*)
                     FROM events
                     WHERE idempotency_key_hash = :hash',
                    ['hash' => $keyHash],
                ),
            );

            self::assertSame(
                1,
                (int) $connection->fetchOne(
                    'SELECT COUNT(*)
                     FROM webhook_deliveries
                     WHERE event_id = :id',
                    ['id' => $eventId],
                ),
            );

            self::assertSame(
                1,
                (int) $connection->fetchOne(
                    'SELECT COUNT(*)
                     FROM outbox_messages o
                     JOIN webhook_deliveries d
                       ON d.id = o.delivery_id
                     WHERE d.event_id = :id',
                    ['id' => $eventId],
                ),
            );

            $row = $connection->fetchAssociative(
                'SELECT type, source, payload, request_hash
                 FROM events
                 WHERE id = :id',
                ['id' => $eventId],
            );

            self::assertIsArray($row);
            self::assertSame($type, $row['type']);
            self::assertSame(
                'idempotency-race-probe',
                $row['source'],
            );
            self::assertNotEmpty($row['request_hash']);

            $payload = json_decode(
                $row['payload'],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            self::assertSame(
                ['raceKey' => $key],
                $payload,
            );
        } finally {
            if ($lockHeld) {
                $connection->fetchOne(
                    'SELECT pg_advisory_unlock(
                        hashtextextended(CAST(:key AS text), 0)
                    )',
                    ['key' => 'incoming-event:' . $keyHash],
                );
            }

            foreach ($processes as $process) {
                if (!is_resource($process)) {
                    continue;
                }

                if (proc_get_status($process)['running']) {
                    proc_terminate($process, SIGKILL);
                }

                proc_close($process);
            }

            $eventId = $connection->fetchOne(
                'SELECT id FROM events
                 WHERE idempotency_key_hash = :hash',
                ['hash' => $keyHash],
            );

            if (is_string($eventId)) {
                $connection->executeStatement(
                    'DELETE FROM outbox_messages
                     WHERE delivery_id IN (
                         SELECT id FROM webhook_deliveries
                         WHERE event_id = :id
                     )',
                    ['id' => $eventId],
                );

                $connection->executeStatement(
                    'DELETE FROM webhook_deliveries
                     WHERE event_id = :id',
                    ['id' => $eventId],
                );

                $connection->executeStatement(
                    'DELETE FROM events WHERE id = :id',
                    ['id' => $eventId],
                );
            }

            if ($endpointId !== null) {
                $connection->executeStatement(
                    'DELETE FROM webhook_endpoints
                     WHERE id = :id',
                    ['id' => $endpointId],
                );
            }

            $em->close();
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
        string $key,
        string $type,
        string $prefix,
        string $worker,
    ) {
        $script = dirname(__DIR__)
            . '/Fixtures/event-idempotency-probe.php';

        $process = proc_open(
            [PHP_BINARY, $script, $key, $type, $prefix, $worker],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $prefix . '.' . $worker . '.stdout', 'w'],
                2 => ['file', $prefix . '.' . $worker . '.stderr', 'w'],
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
            'Idempotency probe failed: ' . $worker
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
