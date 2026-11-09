<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateEventRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 150)]
        public string $type,

        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $source,

        public array $payload,
    ) {}
}
