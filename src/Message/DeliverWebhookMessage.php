<?php

declare(strict_types=1);

namespace App\Message;

final readonly class DeliverWebhookMessage
{
    public function __construct(
        public string $deliveryId,
        public int $replayGeneration = 0,
    ) {}
}
