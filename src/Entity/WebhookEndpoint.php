<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'webhook_endpoints')]
#[ORM\Index(
    name: 'idx_webhook_endpoints_org_active',
    columns: ['organization_id', 'is_active'],
)]
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

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $signingSecret;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(
        name: 'organization_id',
        referencedColumnName: 'id',
        nullable: false,
        foreignKeyName: 'fk_webhook_endpoints_organization',
        options: ['default' => Organization::LEGACY_ID],
    )]
    private ?Organization $organization = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * @param list<string> $subscribedEvents
     */
    public function __construct(
        string $url,
        array $subscribedEvents,
        ?Organization $organization = null,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->url = $url;
        $this->subscribedEvents = $subscribedEvents;
        $this->isActive = true;
        $this->signingSecret = bin2hex(random_bytes(32));
        $this->organization = $organization;

        $this->createdAt = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrganizationId(): string
    {
        return $this->organization?->getId()
            ?? Organization::LEGACY_ID;
    }

    #[ORM\PrePersist]
    public function assignLegacyOrganization(PrePersistEventArgs $args): void
    {
        if ($this->organization !== null) {
            return;
        }

        $legacy = $args->getObjectManager()->find(
            Organization::class,
            Organization::LEGACY_ID,
        );

        if (!$legacy instanceof Organization) {
            throw new LogicException('Legacy organization is unavailable.');
        }

        $this->organization = $legacy;
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

    public function getSigningSecret(): string
    {
        return $this->signingSecret;
    }

    public function rotateSigningSecret(): string
    {
        $this->signingSecret = bin2hex(random_bytes(32));

        return $this->signingSecret;
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
