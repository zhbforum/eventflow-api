<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use stdClass;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'events')]
#[ORM\UniqueConstraint(
    name: 'uniq_events_idempotency_key_hash',
    columns: ['idempotency_key_hash'],
)]
#[ORM\Index(
    name: 'idx_events_org_created',
    columns: ['organization_id', 'created_at'],
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

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(
        name: 'organization_id',
        referencedColumnName: 'id',
        nullable: false,
        foreignKeyName: 'fk_events_organization',
        options: ['default' => Organization::LEGACY_ID],
    )]
    private ?Organization $organization = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $type,
        string $source,
        array|stdClass $payload,
        ?Organization $organization = null,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->type = $type;
        $this->source = $source;
        $this->payload = $payload;
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
