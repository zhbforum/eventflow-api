<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class OrganizationHttpAccessControllerTest extends WebTestCase
{
    public function testOidcRequiresOrganizationSelection(): void
    {
        $client = static::createClient();

        $client->loginUser(
            new OidcUser(
                'tenant-test-user',
                'test-user',
                null,
                ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
                time(),
            ),
            'main',
        );

        $client->request('GET', '/api/v1/events');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testOidcCannotSelectForeignOrganization(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $organization = new Organization(
            'http-access-' . bin2hex(random_bytes(8)),
            'Protected Organization',
        );

        $membership = new OrganizationMembership(
            $organization,
            'organization-owner',
            'admin',
        );

        $em->persist($organization);
        $em->persist($membership);
        $em->flush();

        $client->loginUser(
            new OidcUser(
                'different-user',
                'different-user',
                null,
                ['ROLE_USER', 'ROLE_EVENTFLOW_ADMIN'],
                time(),
            ),
            'main',
        );

        $client->request(
            'GET',
            '/api/v1/events',
            [],
            [],
            [
                'HTTP_X_ORGANIZATION_ID' =>
                $organization->getId(),
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testManagementTokenCannotSelectForeignOrganization(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/api/v1/events',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' =>
                'Bearer eventflow-test-management-token',
                'HTTP_X_ORGANIZATION_ID' =>
                Uuid::v7()->toRfc4122(),
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }
}
