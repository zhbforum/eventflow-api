<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

final class TestWebhookReceiver
{
    private const MAX_AGE_SECONDS = 300;

    /** @var array<string, true> */
    private array $receivedDeliveryIds = [];

    public function __construct(
        private readonly string $signingSecret,
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public function accepts(
        string $rawBody,
        array $headers,
        int $now,
    ): bool {
        $deliveryId = $headers['x-eventflow-delivery-id'] ?? '';
        $timestampHeader = $headers['x-eventflow-timestamp'] ?? '';
        $signature = $headers['x-eventflow-signature'] ?? '';

        if (
            $deliveryId === ''
            || ($headers['content-type'] ?? '') !== 'application/json'
            || !preg_match('/^[1-9][0-9]*$/D', $timestampHeader)
            || !preg_match('/^v1=[a-f0-9]{64}$/D', $signature)
        ) {
            return false;
        }

        $timestamp = filter_var(
            $timestampHeader,
            FILTER_VALIDATE_INT,
        );

        if (
            $timestamp === false
            || abs($now - $timestamp) > self::MAX_AGE_SECONDS
            || isset($this->receivedDeliveryIds[$deliveryId])
        ) {
            return false;
        }

        $expected = 'v1=' . hash_hmac(
            'sha256',
            sprintf(
                'v1.%s.%s.%s',
                $timestampHeader,
                $deliveryId,
                $rawBody,
            ),
            $this->signingSecret,
        );

        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $this->receivedDeliveryIds[$deliveryId] = true;

        return true;
    }
}
