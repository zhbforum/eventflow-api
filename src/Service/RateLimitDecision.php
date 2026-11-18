<?php

declare(strict_types=1);

namespace App\Service;

final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public ?int $retryAfter,
    ) {}
}
