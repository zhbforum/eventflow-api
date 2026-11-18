<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Security\OidcUser;
use App\Security\OrganizationMembershipResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class OrganizationMembershipResolverIntegrationTest extends KernelTestCase
{
    public function testMembershipIsScopedToUserAndOrganization(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $em = $container->get(EntityManagerInterface::class);

        $resolver = new OrganizationMembershipResolver($em);

        $suffix = bin2hex(random_bytes(8));

        $organizationA = new Organization(
            'access-a-' . $suffix,
            'Organization A',
        );

        $organizationB = new Organization(
            'access-b-' . $suffix,
            'Organization B',
        );

        $admin = new OrganizationMembership(
            $organizationA,
            'oidc-user-a',
            'admin',
        );

        $viewer = new OrganizationMembership(
            $organizationB,
            'oidc-user-a',
            'viewer',
        );

        foreach (
            [$organizationA, $organizationB, $admin, $viewer]
            as $entity
        ) {
            $em->persist($entity);
        }

        $em->flush();
        $em->clear();

        $userA = new OidcUser(
            'oidc-user-a',
            'user-a',
            null,
            ['ROLE_USER', 'ROLE_EVENTFLOW_ADMIN'],
            time(),
        );

        $userB = new OidcUser(
            'oidc-user-b',
            'user-b',
            null,
            ['ROLE_USER', 'ROLE_EVENTFLOW_ADMIN'],
            time(),
        );

        $membershipA = $resolver->resolve(
            $userA,
            $organizationA->getId(),
        );

        self::assertInstanceOf(
            OrganizationMembership::class,
            $membershipA,
        );

        self::assertTrue($membershipA->isAdmin());

        $membershipB = $resolver->resolve(
            $userA,
            $organizationB->getId(),
        );

        self::assertInstanceOf(
            OrganizationMembership::class,
            $membershipB,
        );

        self::assertFalse($membershipB->isAdmin());

        self::assertNull(
            $resolver->resolve(
                $userB,
                $organizationA->getId(),
            ),
        );

        self::assertNull(
            $resolver->resolve(
                $userB,
                $organizationB->getId(),
            ),
        );

        self::assertNull(
            $resolver->resolve($userA, 'invalid-uuid'),
        );

        self::assertNull(
            $resolver->resolve(
                $userA,
                Uuid::v7()->toRfc4122(),
            ),
        );
    }
}
