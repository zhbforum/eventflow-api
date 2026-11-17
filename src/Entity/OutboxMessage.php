<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'outbox_messages')]
#[ORM\UniqueConstraint(
    name: 'uniq_outbox_delivery_generation',
    columns: ['delivery_id', 'replay_generation'],
)]
final class OutboxMessage
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: WebhookDelivery::class)]
    #[ORM\JoinColumn(nullable: false)]
    private WebhookDelivery $delivery;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $publishAttempts = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $replayGeneration = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    public function __construct(WebhookDelivery $delivery)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->delivery = $delivery;
        $this->replayGeneration = $delivery->getReplayGeneration();
        $this->createdAt = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDelivery(): WebhookDelivery
    {
        return $this->delivery;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getReplayGeneration(): int
    {
        return $this->replayGeneration;
    }

    public function getPublishAttempts(): int
    {
        return $this->publishAttempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function startPublishAttempt(): void
    {
        ++$this->publishAttempts;
    }

    public function markPublished(): void
    {
        $this->publishedAt = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
        $this->lastError = null;
    }

    public function recordFailure(string $error): void
    {
        $this->lastError = $error;
    }
}
