<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

final readonly class WebhookSigner
{
    public function sign(
        string $rawBody,
        string $deliveryId,
        int $timestamp,
        string $secret,
    ): string {
        if ($deliveryId === '' || $secret === '' || $timestamp <= 0) {
            throw new InvalidArgumentException(
                'Webhook signing requires a delivery ID, timestamp, and secret.',
            );
        }

        $signedContent = sprintf(
            'v1.%d.%s.%s',
            $timestamp,
            $deliveryId,
            $rawBody,
        );

        return 'v1=' . hash_hmac('sha256', $signedContent, $secret);
    }
}
