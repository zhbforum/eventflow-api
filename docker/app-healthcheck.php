<?php

declare(strict_types=1);

$context = stream_context_create([
    'http' => [
        'timeout' => 8,
    ],
]);

$response = @file_get_contents(
    'http://127.0.0.1:8000/health/ready',
    false,
    $context,
);

if ($response === false) {
    fwrite(
        STDERR,
        "Readiness endpoint is unavailable.\n",
    );

    exit(1);
}

try {
    $data = json_decode(
        $response,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
} catch (JsonException) {
    fwrite(
        STDERR,
        "Invalid readiness response.\n",
    );

    exit(1);
}

if (
    !is_array($data)
    || ($data['status'] ?? null) !== 'ready'
    || ($data['checks']['database'] ?? null) !== 'up'
) {
    fwrite(
        STDERR,
        "Application is not ready.\n",
    );

    exit(1);
}

exit(0);
