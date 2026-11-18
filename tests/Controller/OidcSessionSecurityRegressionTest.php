<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class OidcSessionSecurityRegressionTest extends WebTestCase
{
    public function testExpiredSessionIsRejected(): void
    {
        $client = static::createClient();

        $client->loginUser(
            new OidcUser(
                'expired-session-user',
                'expired-user',
                null,
                [
                    'ROLE_USER',
                    'ROLE_EVENTFLOW_ADMIN',
                ],
                time() - 3601,
            ),
            'main',
        );

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
        );

        $client->request('GET', '/api/v1/auth/csrf');

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
        );

        $client->request('GET', '/api/v1/events');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testFutureDatedSessionIsRejected(): void
    {
        $client = static::createClient();

        $client->loginUser(
            new OidcUser(
                'future-session-user',
                'future-user',
                null,
                [
                    'ROLE_USER',
                    'ROLE_EVENTFLOW_ADMIN',
                ],
                time() + 3600,
            ),
            'main',
        );

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
        );
    }

    public function testRevokedMembershipImmediatelyRemovesAccess(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));
        $subject = 'session-revocation-' . $suffix;

        $organization = new Organization(
            'session-revocation-' . $suffix,
            'Session Revocation Organization',
        );

        $membership = new OrganizationMembership(
            $organization,
            $subject,
            'viewer',
        );

        $em->persist($organization);
        $em->persist($membership);
        $em->flush();

        $organizationId = $organization->getId();
        $membershipId = $membership->getId();

        $client->loginUser(
            new OidcUser(
                $subject,
                $subject,
                null,
                [
                    'ROLE_USER',
                    'ROLE_EVENTFLOW_VIEWER',
                ],
                time(),
            ),
            'main',
        );

        $headers = [
            'HTTP_X_ORGANIZATION_ID' => $organizationId,
        ];

        $client->request(
            'GET',
            '/api/v1/events',
            [],
            [],
            $headers,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $savedMembership = $em->find(
            OrganizationMembership::class,
            $membershipId,
        );

        self::assertInstanceOf(
            OrganizationMembership::class,
            $savedMembership,
        );

        $em->remove($savedMembership);
        $em->flush();

        $client->request(
            'GET',
            '/api/v1/events',
            [],
            [],
            $headers,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );
    }
}
