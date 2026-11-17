<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use stdClass;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'events')]
#[ORM\UniqueConstraint(
    name: 'uniq_events_idempotency_key_hash',
    columns: ['idempotency_key_hash'],
)]
final class Event
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(length: 150)]
    private string $type;

    #[ORM\Column(length: 100)]
    private string $source;

    #[ORM\Column(type: 'json_object')]
    private array|stdClass $payload;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $idempotencyKeyHash = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $requestHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $type,
        string $source,
        array|stdClass $payload,
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

    public function getPayload(): array|stdClass
    {
        return $this->payload;
    }

    public function getIdempotencyKeyHash(): ?string
    {
        return $this->idempotencyKeyHash;
    }

    public function getRequestHash(): ?string
    {
        return $this->requestHash;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function assignIdempotency(
        string $keyHash,
        string $requestHash,
    ): void {
        if ($this->idempotencyKeyHash !== null) {
            throw new LogicException(
                'Event idempotency cannot be reassigned.',
            );
        }

        $this->idempotencyKeyHash = $keyHash;
        $this->requestHash = $requestHash;
    }
}
