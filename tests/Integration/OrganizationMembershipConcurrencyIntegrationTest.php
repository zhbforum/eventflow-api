<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Fixtures\OutboxCrashDatabase;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class OrganizationMembershipConcurrencyIntegrationTest extends TestCase
{
    public function testRevokedAdminCannotCreateMemberAfterWaitingOnLock(): void
    {
        $this->runScenario('create');
    }

    public function testConcurrentAdminRemovalsLeaveAnAdmin(): void
    {
        $this->runScenario('delete');
    }

    private function runScenario(string $mode): void
    {
        if (
            PHP_OS_FAMILY !== 'Linux'
            || !function_exists('proc_open')
            || !function_exists('proc_terminate')
            || !defined('SIGKILL')
        ) {
            self::markTestSkipped('Requires Linux process control.');
        }

        $connection = OutboxCrashDatabase::connect();
        $prefix = tempnam(sys_get_temp_dir(), 'eventflow_org_race_');
        self::assertIsString($prefix);

        $fixture = null;
        $processes = [];
        $lockHeld = false;

        try {
            $fixture = self::seed($connection);
            file_put_contents(
                $prefix,
                json_encode($fixture, JSON_THROW_ON_ERROR),
            );

            $workers = $mode === 'create' ? ['b'] : ['a', 'b'];

            foreach ($workers as $worker) {
                $processes[$worker] = self::startProbe(
                    $prefix,
                    $mode,
                    $worker,
                );
            }

            self::waitForReady($prefix, $processes);

            $connection->beginTransaction();
            $lockHeld = true;

            $lockedId = $connection->fetchOne(
                'SELECT id FROM organizations
                 WHERE id = :id FOR UPDATE',
                ['id' => $fixture['organizationId']],
            );

            self::assertSame($fixture['organizationId'], $lockedId);

            file_put_contents($prefix . '.go', '1');

            self::waitForWaitingProcesses(
                $connection,
                count($workers),
                $processes,
                $prefix,
            );

            if ($mode === 'create') {
                $connection->executeStatement(
                    'DELETE FROM organization_memberships
                     WHERE id = :id',
                    ['id' => $fixture['adminBId']],
                );
            }

            $connection->commit();
            $lockHeld = false;

            $results = [];

            foreach ($workers as $worker) {
                $results[$worker] = self::waitForResult(
                    $prefix,
                    $worker,
                    $processes[$worker],
                );
            }

            if ($mode === 'create') {
                self::assertSame(
                    403,
                    $results['b']['status'],
                    json_encode($results, JSON_THROW_ON_ERROR),
                );

                self::assertSame(
                    0,
                    (int) $connection->fetchOne(
                        'SELECT COUNT(*)
                         FROM organization_memberships
                         WHERE organization_id = :id
                           AND oidc_subject = :subject',
                        [
                            'id' => $fixture['organizationId'],
                            'subject' => $fixture['targetSubject'],
                        ],
                    ),
                );
            } else {
                $statuses = [
                    $results['a']['status'],
                    $results['b']['status'],
                ];
                sort($statuses);

                self::assertSame(
                    [204, 403],
                    $statuses,
                    json_encode($results, JSON_THROW_ON_ERROR),
                );
            }

            self::assertSame(
                1,
                (int) $connection->fetchOne(
                    'SELECT COUNT(*)
                     FROM organization_memberships
                     WHERE organization_id = :id AND role = :role',
                    [
                        'id' => $fixture['organizationId'],
                        'role' => 'admin',
                    ],
                ),
            );
        } finally {
            if ($lockHeld) {
                $connection->rollBack();
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

            if ($fixture !== null) {
                $connection->executeStatement(
                    'DELETE FROM organization_memberships
                     WHERE organization_id = :id',
                    ['id' => $fixture['organizationId']],
                );

                $connection->executeStatement(
                    'DELETE FROM organizations WHERE id = :id',
                    ['id' => $fixture['organizationId']],
                );
            }

            $connection->close();

            foreach (['a', 'b'] as $worker) {
                foreach (
                    ['ready', 'result', 'stdout', 'stderr'] as $ending
                ) {
                    @unlink($prefix . '.' . $worker . '.' . $ending);
                }
            }

            @unlink($prefix . '.go');
            @unlink($prefix);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function seed(Connection $connection): array
    {
        $suffix = bin2hex(random_bytes(8));
        $organizationId = Uuid::v7()->toRfc4122();
        $adminAId = Uuid::v7()->toRfc4122();
        $adminBId = Uuid::v7()->toRfc4122();
        $adminASubject = 'org-race-a-' . $suffix;
        $adminBSubject = 'org-race-b-' . $suffix;

        $connection->executeStatement(
            'INSERT INTO organizations (id, slug, name, created_at)
             VALUES (:id, :slug, :name, CURRENT_TIMESTAMP)',
            [
                'id' => $organizationId,
                'slug' => 'org-race-' . $suffix,
                'name' => 'Organization race test',
            ],
        );

        foreach (
            [
                [$adminAId, $adminASubject],
                [$adminBId, $adminBSubject],
            ] as [$memberId, $subject]
        ) {
            $connection->executeStatement(
                'INSERT INTO organization_memberships (
                    id, organization_id, oidc_subject, role, created_at
                 ) VALUES (
                    :id, :org, :subject, :role, CURRENT_TIMESTAMP
                 )',
                [
                    'id' => $memberId,
                    'org' => $organizationId,
                    'subject' => $subject,
                    'role' => 'admin',
                ],
            );
        }

        return [
            'organizationId' => $organizationId,
            'adminAId' => $adminAId,
            'adminBId' => $adminBId,
            'adminASubject' => $adminASubject,
            'adminBSubject' => $adminBSubject,
            'targetSubject' => 'org-race-target-' . $suffix,
            'appPrefix' => 'eventflow-org-' . $suffix,
        ];
    }

    /**
     * @return resource
     */
    private static function startProbe(
        string $prefix,
        string $mode,
        string $worker,
    ) {
        $script = dirname(__DIR__)
            . '/Fixtures/organization-membership-probe.php';

        $process = proc_open(
            [PHP_BINARY, $script, $prefix, $mode, $worker],
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
     * @param array<string, resource> $processes
     */
    private static function waitForReady(
        string $prefix,
        array $processes,
    ): void {
        for ($attempt = 0; $attempt < 1200; ++$attempt) {
            $ready = 0;

            foreach ($processes as $worker => $process) {
                $path = $prefix . '.' . $worker . '.ready';
                clearstatcache(true, $path);

                if (is_file($path)) {
                    ++$ready;
                    continue;
                }

                $result = $prefix . '.' . $worker . '.result';
                clearstatcache(true, $result);

                if (
                    is_file($result)
                    || !proc_get_status($process)['running']
                ) {
                    self::fail(self::diagnostics($prefix, $worker));
                }
            }

            if ($ready === count($processes)) {
                return;
            }

            usleep(50_000);
        }

        self::fail(
            'Symfony probe startup timed out. ' . implode(
                "\n",
                array_map(
                    static fn(string $worker): string =>
                    self::diagnostics($prefix, $worker),
                    array_keys($processes),
                ),
            ),
        );
    }

    /**
     * @param array<string, resource> $processes
     */
    private static function waitForWaitingProcesses(
        Connection $connection,
        int $expected,
        array $processes,
        string $prefix,
    ): void {
        $waiting = 0;

        for ($attempt = 0; $attempt < 400; ++$attempt) {
            $connection->fetchOne('SELECT pg_stat_clear_snapshot()');

            $waiting = (int) $connection->fetchOne(
                'WITH RECURSIVE blocked(pid) AS (
                    SELECT pg_backend_pid()
                    UNION
                    SELECT a.pid
                    FROM pg_stat_activity a
                    INNER JOIN blocked b
                      ON b.pid = ANY(pg_blocking_pids(a.pid))
                    WHERE a.datname = current_database()
                 )
                 SELECT COUNT(DISTINCT pid) - 1 FROM blocked',
            );

            if ($waiting === $expected) {
                return;
            }

            foreach ($processes as $worker => $process) {
                $result = $prefix . '.' . $worker . '.result';
                clearstatcache(true, $result);

                if (is_file($result)) {
                    self::fail(
                        'Probe returned before reaching the lock: '
                            . self::diagnostics($prefix, $worker),
                    );
                }

                if (!proc_get_status($process)['running']) {
                    self::fail(self::diagnostics($prefix, $worker));
                }
            }

            usleep(50_000);
        }

        $connection->fetchOne('SELECT pg_stat_clear_snapshot()');
        $activity = $connection->fetchAllAssociative(
            "SELECT pid, application_name, state,
                    wait_event_type, wait_event,
                    pg_blocking_pids(pid) AS blockers,
                    LEFT(query, 150) AS query
             FROM pg_stat_activity
             WHERE datname = current_database()
               AND pid <> pg_backend_pid()
             ORDER BY pid",
        );

        self::fail(
            'Expected PostgreSQL lock waiters: ' . $expected
                . '; observed: ' . $waiting
                . "\nDatabase sessions: "
                . json_encode($activity, JSON_THROW_ON_ERROR)
                . "\n"
                . implode(
                    "\n",
                    array_map(
                        static fn(string $worker): string =>
                        self::diagnostics($prefix, $worker),
                        array_keys($processes),
                    ),
                ),
        );
    }

    /**
     * @param resource $process
     *
     * @return array<string, mixed>
     */
    private static function waitForResult(
        string $prefix,
        string $worker,
        $process,
    ): array {
        $path = $prefix . '.' . $worker . '.result';

        for ($attempt = 0; $attempt < 600; ++$attempt) {
            clearstatcache(true, $path);

            if (is_file($path) && filesize($path) > 0) {
                $result = json_decode(
                    (string) file_get_contents($path),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                self::assertIsArray($result);

                return $result;
            }

            if (!proc_get_status($process)['running']) {
                break;
            }

            usleep(50_000);
        }

        self::fail(self::diagnostics($prefix, $worker));
    }

    private static function diagnostics(
        string $prefix,
        string $worker,
    ): string {
        $base = $prefix . '.' . $worker;

        return 'Organization race probe ' . $worker
            . "\nREADY: "
            . (is_file($base . '.ready') ? 'yes' : 'no')
            . "\nRESULT: "
            . (string) @file_get_contents($base . '.result')
            . "\nSTDOUT:\n"
            . (string) @file_get_contents($base . '.stdout')
            . "\nSTDERR:\n"
            . (string) @file_get_contents($base . '.stderr');
    }
}
