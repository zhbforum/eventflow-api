<?php

declare(strict_types=1);

use App\Service\DeliveryReplayService;
use App\Tests\Fixtures\OutboxCrashDatabase;

require dirname(__DIR__) . '/bootstrap.php';

$deliveryId = $argv[1] ?? '';
$prefix = $argv[2] ?? '';
$worker = $argv[3] ?? '';

if (
    $deliveryId === ''
    || $prefix === ''
    || !in_array($worker, ['a', 'b'], true)
) {
    fwrite(STDERR, "Invalid replay probe arguments.\n");
    exit(2);
}

$entityManager = OutboxCrashDatabase::entityManager();

try {
    // Tell the parent that this independent database
    // connection is ready to attempt replay.
    file_put_contents(
        $prefix . '.' . $worker . '.ready',
        '1',
    );

    try {
        $replay = (new DeliveryReplayService(
            $entityManager,
        ))->requestReplay(
            $deliveryId,
            'race-' . $worker,
        );

        if ($replay === null) {
            throw new RuntimeException(
                'Delivery unexpectedly disappeared.',
            );
        }

        $result = [
            'outcome' => 'accepted',
            'generation' => $replay->getReplayGeneration(),
        ];
    } catch (LogicException) {
        $result = [
            'outcome' => 'conflict',
        ];
    }

    file_put_contents(
        $prefix . '.' . $worker . '.result',
        json_encode($result, JSON_THROW_ON_ERROR),
    );
} finally {
    $entityManager->getConnection()->close();
}
