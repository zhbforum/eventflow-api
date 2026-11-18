<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'organization_memberships')]
#[ORM\UniqueConstraint(
    name: 'uniq_org_memberships_org_subject',
    columns: ['organization_id', 'oidc_subject'],
)]
#[ORM\Index(
    name: 'idx_org_memberships_subject',
    columns: ['oidc_subject'],
)]
final class OrganizationMembership
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(
        name: 'organization_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    private Organization $organization;

    #[ORM\Column(length: 255)]
    private string $oidcSubject;

    #[ORM\Column(length: 10)]
    private string $role;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Organization $organization,
        string $oidcSubject,
        string $role,
    ) {
        if (
            $oidcSubject === ''
            || trim($oidcSubject) !== $oidcSubject
            || strlen($oidcSubject) > 255
        ) {
            throw new InvalidArgumentException(
                'Invalid OIDC subject.',
            );
        }

        if (!in_array($role, ['admin', 'viewer'], true)) {
            throw new InvalidArgumentException(
                'Invalid organization membership role.',
            );
        }

        $this->id = Uuid::v7()->toRfc4122();
        $this->organization = $organization;
        $this->oidcSubject = $oidcSubject;
        $this->role = $role;
        $this->createdAt = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getOidcSubject(): string
    {
        return $this->oidcSubject;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
