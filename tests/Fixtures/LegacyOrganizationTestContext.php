<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class LegacyOrganizationTestContext
{
    public static function enable(
        KernelBrowser $client,
        EntityManagerInterface $entityManager,
        string $subject,
        string $role,
    ): void {
        $organization = $entityManager->find(
            Organization::class,
            Organization::LEGACY_ID,
        );

        if (!$organization instanceof Organization) {
            throw new LogicException('Legacy organization is unavailable.');
        }

        $existing = $entityManager
            ->getRepository(OrganizationMembership::class)
            ->findOneBy([
                'organization' => $organization,
                'oidcSubject' => $subject,
            ]);

        if ($existing === null) {
            $entityManager->persist(new OrganizationMembership(
                $organization,
                $subject,
                $role,
            ));
            $entityManager->flush();
        } elseif ($existing->getRole() !== $role) {
            throw new LogicException('Unexpected membership role in test.');
        }

        $client->setServerParameter(
            'HTTP_X_ORGANIZATION_ID',
            Organization::LEGACY_ID,
        );
    }
}
