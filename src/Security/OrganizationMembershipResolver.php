<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\OrganizationMembership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class OrganizationMembershipResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function resolve(
        OidcUser $user,
        string $organizationId,
    ): ?OrganizationMembership {
        if (!Uuid::isValid($organizationId)) {
            return null;
        }

        return $this->entityManager
            ->getRepository(OrganizationMembership::class)
            ->findOneBy([
                'organization' => $organizationId,
                'oidcSubject' => $user->getUserIdentifier(),
            ]);
    }
}
