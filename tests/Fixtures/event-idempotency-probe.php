<?php

declare(strict_types=1);

use App\Service\EventPublisher;
use App\Service\EventRequestFingerprint;
use App\Tests\Fixtures\OutboxCrashDatabase;

require dirname(__DIR__) . '/bootstrap.php';

$key = $argv[1] ?? '';
$type = $argv[2] ?? '';
$prefix = $argv[3] ?? '';
$worker = $argv[4] ?? '';

if (
    $key === ''
    || $type === ''
    || $prefix === ''
    || !in_array($worker, ['a', 'b'], true)
) {
    fwrite(STDERR, "Invalid probe arguments.\n");
    exit(2);
}

$em = OutboxCrashDatabase::entityManager();
$connection = $em->getConnection();

try {
    $connection->fetchOne(
        "SELECT set_config('application_name', :name, false)",
        [
            'name' => 'eventflow-idem-' . $worker . '-'
                . substr(
                    EventRequestFingerprint::keyHash(
                        'integration-probe',
                        $key,
                    ),
                    0,
                    12,
                ),
        ],
    );

    file_put_contents(
        $prefix . '.' . $worker . '.ready',
        '1',
    );

    try {
        $publication = (new EventPublisher($em))
            ->publishIdempotent(
                $type,
                'idempotency-race-probe',
                ['raceKey' => $key],
                $key,
                json_encode(
                    [
                        'type' => $type,
                        'source' => 'idempotency-race-probe',
                        'payload' => ['raceKey' => $key],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
                'integration-probe',
            );

        $result = [
            'outcome' => $publication->replayed
                ? 'replayed'
                : 'created',
            'eventId' => $publication->event->getId(),
        ];
    } catch (Throwable $exception) {
        $result = [
            'outcome' => 'error',
            'message' => $exception->getMessage(),
        ];
    }

    file_put_contents(
        $prefix . '.' . $worker . '.result',
        json_encode($result, JSON_THROW_ON_ERROR),
    );
} finally {
    $em->close();
    $connection->close();
}
