<?php

declare(strict_types=1);

use App\Service\PostgreSqlRateLimiter;
use App\Tests\Fixtures\OutboxCrashDatabase;

require dirname(__DIR__) . '/bootstrap.php';

$key = $argv[1] ?? '';
$limit = (int) ($argv[2] ?? 0);
$directory = $argv[3] ?? '';
$workerId = $argv[4] ?? '';

if (
    preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1
    || $limit < 1
    || !is_dir($directory)
    || !ctype_digit($workerId)
) {
    fwrite(STDERR, "Invalid concurrency probe arguments.\n");
    exit(2);
}

$connection = OutboxCrashDatabase::connect();

try {
    $readyFile = $directory . '/ready-' . $workerId;
    $startFile = $directory . '/start';
    $resultFile = $directory . '/result-' . $workerId;

    if (file_put_contents($readyFile, '1') === false) {
        throw new RuntimeException('Cannot signal readiness.');
    }

    $deadline = microtime(true) + 25;

    while (!is_file($startFile)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Start barrier timed out.');
        }

        usleep(50_000);
    }

    $decision = (new PostgreSqlRateLimiter(
        $connection,
    ))->consume($key, $limit, 600);

    $result = $decision->allowed ? 'allowed' : 'blocked';

    if (file_put_contents($resultFile, $result) === false) {
        throw new RuntimeException('Cannot write probe result.');
    }
} finally {
    $connection->close();
}
