<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;

final readonly class EventPublicationResult
{
    public function __construct(
        public Event $event,
        public bool $replayed,
    ) {}
}
