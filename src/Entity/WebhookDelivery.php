<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use LogicException;

#[ORM\Entity]
#[ORM\Table(name: 'webhook_deliveries')]
#[ORM\UniqueConstraint(
    name: 'uniq_delivery_event_endpoint',
    columns: ['event_id', 'endpoint_id'],
)]
final class WebhookDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Event $event;

    #[ORM\ManyToOne(targetEntity: WebhookEndpoint::class)]
    #[ORM\JoinColumn(nullable: false)]
    private WebhookEndpoint $endpoint;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column(type: Types::INTEGER)]
    private int $attempts = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $replayGeneration = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    public function __construct(
        Event $event,
        WebhookEndpoint $endpoint,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->event = $event;
        $this->endpoint = $endpoint;
        $this->createdAt = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getEndpoint(): WebhookEndpoint
    {
        return $this->endpoint;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getReplayGeneration(): int
    {
        return $this->replayGeneration;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeliveredAt(): ?DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }


    public function requestReplay(): int
    {
        if ($this->status !== 'failed') {
            throw new LogicException(
                'Only failed webhook deliveries can be replayed.',
            );
        }

        ++$this->replayGeneration;
        $this->status = 'pending';
        $this->lastError = null;
        $this->deliveredAt = null;

        return $this->replayGeneration;
    }

    public function startAttempt(): void
    {
        ++$this->attempts;
        $this->status = 'processing';
    }

    public function markDelivered(): void
    {
        $this->status = 'delivered';
        $this->deliveredAt = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
        $this->lastError = null;
    }

    public function recordFailure(string $error): void
    {
        $this->status = 'pending';
        $this->lastError = $error;
    }

    public function markFailed(string $error): void
    {
        $this->status = 'failed';
        $this->lastError = $error;
    }
}
