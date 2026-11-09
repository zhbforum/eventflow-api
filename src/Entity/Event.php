<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'events')]
final class Event
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(length: 150)]
    private string $type;

    #[ORM\Column(length: 100)]
    private string $source;

    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $type,
        string $source,
        array $payload,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->type = $type;
        $this->source = $source;
        $this->payload = $payload;
        $this->createdAt = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
