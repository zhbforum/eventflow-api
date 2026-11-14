<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'webhook_endpoints')]
final class WebhookEndpoint
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(length: 2048)]
    private string $url;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $subscribedEvents;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * @param list<string> $subscribedEvents
     */
    public function __construct(
        string $url,
        array $subscribedEvents,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->url = $url;
        $this->subscribedEvents = $subscribedEvents;
        $this->isActive = true;
        $this->createdAt = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /** @return list<string> */
    public function getSubscribedEvents(): array
    {
        return $this->subscribedEvents;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function update(
        string $url,
        array $subscribedEvents,
        bool $isActive,
    ): void {
        $this->url = $url;
        $this->subscribedEvents = $subscribedEvents;
        $this->isActive = $isActive;
    }
}
