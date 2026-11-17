<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'webhook_delivery_replays')]
#[ORM\UniqueConstraint(
    name: 'uniq_delivery_replay_generation',
    columns: ['delivery_id', 'replay_generation'],
)]
final class WebhookDeliveryReplay
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: WebhookDelivery::class)]
    #[ORM\JoinColumn(nullable: false)]
    private WebhookDelivery $delivery;

    #[ORM\Column(type: Types::INTEGER)]
    private int $replayGeneration;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $requestedAt;

    #[ORM\Column(length: 255)]
    private string $requestedBy;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $previousError;

    public function __construct(
        WebhookDelivery $delivery,
        string $requestedBy,
        ?string $previousError,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->delivery = $delivery;
        $this->replayGeneration = $delivery->getReplayGeneration();
        $this->requestedAt = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
        $this->requestedBy = $requestedBy;
        $this->previousError = $previousError;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDelivery(): WebhookDelivery
    {
        return $this->delivery;
    }

    public function getReplayGeneration(): int
    {
        return $this->replayGeneration;
    }

    public function getRequestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getRequestedBy(): string
    {
        return $this->requestedBy;
    }

    public function getPreviousError(): ?string
    {
        return $this->previousError;
    }
}
