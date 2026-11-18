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
#[ORM\Table(name: 'organizations')]
#[ORM\UniqueConstraint(
    name: 'uniq_organizations_slug',
    columns: ['slug'],
)]
final class Organization
{
    public const LEGACY_ID = '019a8d00-0000-7000-8000-000000000001';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(length: 63)]
    private string $slug;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $slug,
        string $name,
    ) {
        if (
            preg_match(
                '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',
                $slug,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Invalid organization slug.',
            );
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 150) {
            throw new InvalidArgumentException(
                'Invalid organization name.',
            );
        }

        $this->id = Uuid::v7()->toRfc4122();
        $this->slug = $slug;
        $this->name = $name;
        $this->createdAt = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
