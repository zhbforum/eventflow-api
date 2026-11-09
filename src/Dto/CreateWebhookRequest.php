<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateWebhookRequest
{
    /**
     * @param list<string> $subscribedEvents
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 2048)]
        #[Assert\Url(
            protocols: ['https'],
            requireTld: true,
        )]
        public string $url,

        #[Assert\Count(min: 1, max: 50)]
        #[Assert\Unique]
        #[Assert\All([
            new Assert\Type('string'),
            new Assert\NotBlank,
            new Assert\Regex(
                pattern: '/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)+$/D',
            ),
        ])]
        public array $subscribedEvents,
    ) {}
}
