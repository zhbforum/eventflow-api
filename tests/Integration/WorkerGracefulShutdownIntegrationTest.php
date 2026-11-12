<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class WorkerGracefulShutdownIntegrationTest extends TestCase
{
    public function testSigtermFinishesCurrentMessageWithoutStartingNext(): void
    {
        if (
            PHP_OS_FAMILY !== 'Linux'
            || !extension_loaded('pcntl')
            || !function_exists('proc_open')
            || !function_exists('proc_terminate')
        ) {
            self::markTestSkipped(
                'Requires Linux, PCNTL and process control.',
            );
        }

        $stateFile = tempnam(
            sys_get_temp_dir(),
            'eventflow_worker_',
        );

        self::assertIsString($stateFile);

        $stdoutFile = $stateFile . '.stdout';
        $stderrFile = $stateFile . '.stderr';

        $fixture = dirname(__DIR__)
            . '/Fixtures/worker-signal-probe.php';

        $process = proc_open(
            [
                PHP_BINARY,
                $fixture,
                $stateFile,
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $stdoutFile, 'w'],
                2 => ['file', $stderrFile, 'w'],
            ],
            $pipes,
        );

        try {
            self::assertIsResource(
                $process,
                'Unable to start worker probe.',
            );

            $started = false;

            for ($attempt = 0; $attempt < 100; ++$attempt) {
                $events = (string) file_get_contents(
                    $stateFile,
                );

                if (str_contains($events, "started:1\n")) {
                    $started = true;
                    break;
                }

                $status = proc_get_status($process);

                if (!$status['running']) {
                    break;
                }

                usleep(50_000);
            }

            self::assertTrue(
                $started,
                'First handler did not start. '
                    . (string) @file_get_contents($stderrFile),
            );

            self::assertTrue(
                proc_get_status($process)['running'],
                'Worker exited before SIGTERM.',
            );

            self::assertTrue(
                proc_terminate($process, SIGTERM),
            );

            $status = proc_get_status($process);

            for (
                $attempt = 0;
                $attempt < 100 && $status['running'];
                ++$attempt
            ) {
                usleep(50_000);
                $status = proc_get_status($process);
            }

            self::assertFalse(
                $status['running'],
                'Worker failed to stop after SIGTERM.',
            );

            self::assertSame(
                0,
                $status['exitcode'],
                'Worker exited abnormally. '
                    . (string) @file_get_contents($stderrFile),
            );

            $events = (string) file_get_contents(
                $stateFile,
            );

            self::assertStringContainsString(
                "started:1\n",
                $events,
            );

            self::assertStringContainsString(
                "finished:1\n",
                $events,
            );

            self::assertStringNotContainsString(
                "started:2\n",
                $events,
            );

            self::assertStringNotContainsString(
                "finished:2\n",
                $events,
            );

            self::assertStringContainsString(
                "acknowledged:1\n",
                $events,
            );

            self::assertStringContainsString(
                "remaining:1\n",
                $events,
            );

            self::assertStringContainsString(
                "exit:0\n",
                $events,
            );
        } finally {
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, SIGKILL);
                }

                proc_close($process);
            }

            @unlink($stateFile);
            @unlink($stdoutFile);
            @unlink($stderrFile);
        }
    }
}
