<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class OrganizationPersistenceIntegrationTest extends KernelTestCase
{
    public function testMembershipsArePersistedAcrossOrganizations(): void
    {
        self::bootKernel();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $subject = 'user-' . bin2hex(random_bytes(8));

        $first = new Organization(
            'org-a-' . bin2hex(random_bytes(6)),
            'Organization A',
        );

        $second = new Organization(
            'org-b-' . bin2hex(random_bytes(6)),
            'Organization B',
        );

        $admin = new OrganizationMembership(
            $first,
            $subject,
            'admin',
        );

        $viewer = new OrganizationMembership(
            $second,
            $subject,
            'viewer',
        );

        foreach ([$first, $second, $admin, $viewer] as $entity) {
            $em->persist($entity);
        }

        $em->flush();
        $em->clear();

        $loadedAdmin = $em->find(
            OrganizationMembership::class,
            $admin->getId(),
        );

        $loadedViewer = $em->find(
            OrganizationMembership::class,
            $viewer->getId(),
        );

        self::assertInstanceOf(
            OrganizationMembership::class,
            $loadedAdmin,
        );

        self::assertInstanceOf(
            OrganizationMembership::class,
            $loadedViewer,
        );

        self::assertSame(
            $first->getId(),
            $loadedAdmin->getOrganization()->getId(),
        );

        self::assertSame(
            $second->getId(),
            $loadedViewer->getOrganization()->getId(),
        );

        self::assertSame($subject, $loadedAdmin->getOidcSubject());
        self::assertSame($subject, $loadedViewer->getOidcSubject());

        self::assertTrue($loadedAdmin->isAdmin());
        self::assertFalse($loadedViewer->isAdmin());
    }

    public function testOrganizationSlugIsUniqueInPostgres(): void
    {
        self::bootKernel();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $organization = new Organization(
            'unique-' . bin2hex(random_bytes(8)),
            'Original',
        );

        $em->persist($organization);
        $em->flush();

        $connection = $em->getConnection();

        $affected = $connection->executeStatement(
            'INSERT INTO organizations (
                id, slug, name, created_at
             ) VALUES (
                :id, :slug, :name, CURRENT_TIMESTAMP
             )
             ON CONFLICT (slug) DO NOTHING',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'slug' => $organization->getSlug(),
                'name' => 'Duplicate',
            ],
        );

        self::assertSame(0, $affected);

        self::assertSame(
            1,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM organizations
                 WHERE slug = :slug',
                ['slug' => $organization->getSlug()],
            ),
        );
    }

    public function testMembershipIsUniqueWithinOrganization(): void
    {
        self::bootKernel();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $organization = new Organization(
            'membership-' . bin2hex(random_bytes(8)),
            'Membership Test',
        );

        $membership = new OrganizationMembership(
            $organization,
            'same-user',
            'admin',
        );

        $em->persist($organization);
        $em->persist($membership);
        $em->flush();

        $connection = $em->getConnection();

        $affected = $connection->executeStatement(
            'INSERT INTO organization_memberships (
                id, organization_id, oidc_subject,
                role, created_at
             ) VALUES (
                :id, :organization_id, :subject,
                :role, CURRENT_TIMESTAMP
             )
             ON CONFLICT (organization_id, oidc_subject)
             DO NOTHING',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'organization_id' => $organization->getId(),
                'subject' => 'same-user',
                'role' => 'viewer',
            ],
        );

        self::assertSame(0, $affected);

        self::assertSame(
            1,
            (int) $connection->fetchOne(
                'SELECT COUNT(*)
                 FROM organization_memberships
                 WHERE organization_id = :organization_id
                   AND oidc_subject = :subject',
                [
                    'organization_id' => $organization->getId(),
                    'subject' => 'same-user',
                ],
            ),
        );
    }
}
