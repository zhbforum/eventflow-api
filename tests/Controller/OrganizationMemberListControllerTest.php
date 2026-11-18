<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class OrganizationMemberListControllerTest extends WebTestCase
{
    public function testAnonymousAndBearerCannotListMembers(): void
    {
        $client = static::createClient();

        $url = '/api/v1/organizations/'
            . Organization::LEGACY_ID
            . '/members';

        $headers = [
            'HTTP_X_ORGANIZATION_ID' => Organization::LEGACY_ID,
        ];

        $client->request('GET', $url, [], [], $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request(
            'GET',
            $url,
            [],
            [],
            [
                ...$headers,
                'HTTP_AUTHORIZATION' =>
                'Bearer eventflow-test-management-token',
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testAdminListsOnlySelectedOrganizationMembers(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));
        $subject = 'members-owner-' . $suffix;

        $organizationA = new Organization(
            'members-a-' . $suffix,
            'Members Organization A',
        );

        $organizationB = new Organization(
            'members-b-' . $suffix,
            'Members Organization B',
        );

        $owner = new OrganizationMembership(
            $organizationA,
            $subject,
            'admin',
        );

        $viewer = new OrganizationMembership(
            $organizationA,
            'members-viewer-' . $suffix,
            'viewer',
        );

        $foreign = new OrganizationMembership(
            $organizationB,
            'members-foreign-' . $suffix,
            'viewer',
        );

        $otherMembership = new OrganizationMembership(
            $organizationB,
            $subject,
            'admin',
        );

        foreach (
            [
                $organizationA,
                $organizationB,
                $owner,
                $viewer,
                $foreign,
                $otherMembership,
            ] as $entity
        ) {
            $em->persist($entity);
        }

        $em->flush();

        self::login($client, $subject, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $url = '/api/v1/organizations/'
            . $organizationA->getId()
            . '/members';

        $headers = [
            'HTTP_X_ORGANIZATION_ID' => $organizationA->getId(),
        ];

        $client->request(
            'GET',
            $url,
            [],
            [],
            $headers,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = self::decode($client);

        self::assertSame(2, $data['pagination']['total']);
        self::assertCount(2, $data['items']);

        self::assertEqualsCanonicalizing(
            [$owner->getId(), $viewer->getId()],
            array_column($data['items'], 'id'),
        );

        self::assertNotContains(
            $foreign->getId(),
            array_column($data['items'], 'id'),
        );

        $roles = array_column(
            $data['items'],
            'role',
            'oidcSubject',
        );

        self::assertSame('admin', $roles[$subject]);
        self::assertSame(
            'viewer',
            $roles['members-viewer-' . $suffix],
        );

        $client->request(
            'GET',
            $url . '?page=2&limit=1',
            [],
            [],
            $headers,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $page = self::decode($client);

        self::assertSame(
            ['page' => 2, 'limit' => 1, 'total' => 2, 'pages' => 2],
            $page['pagination'],
        );

        self::assertCount(1, $page['items']);

        $client->request(
            'GET',
            '/api/v1/organizations/'
                . $organizationB->getId()
                . '/members',
            [],
            [],
            $headers,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );
    }

    public function testRequiresBothGlobalAndOrganizationAdmin(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));

        $organization = new Organization(
            'members-rbac-' . $suffix,
            'Membership RBAC Organization',
        );

        $adminSubject = 'members-admin-' . $suffix;
        $viewerSubject = 'members-viewer-' . $suffix;

        foreach (
            [
                $organization,
                new OrganizationMembership(
                    $organization,
                    $adminSubject,
                    'admin',
                ),
                new OrganizationMembership(
                    $organization,
                    $viewerSubject,
                    'viewer',
                ),
            ] as $entity
        ) {
            $em->persist($entity);
        }

        $em->flush();

        $url = '/api/v1/organizations/'
            . $organization->getId()
            . '/members';

        $headers = [
            'HTTP_X_ORGANIZATION_ID' => $organization->getId(),
        ];

        self::login($client, $adminSubject, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_VIEWER',
        ]);

        $client->request('GET', $url, [], [], $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        self::login($client, $viewerSubject, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $client->request('GET', $url, [], [], $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        self::login(
            $client,
            'members-stranger-' . $suffix,
            ['ROLE_USER', 'ROLE_EVENTFLOW_ADMIN'],
        );

        $client->request('GET', $url, [], [], $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        self::login($client, $adminSubject, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $client->request('GET', $url);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testInvalidPaginationIsRejected(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));
        $subject = 'members-pagination-' . $suffix;

        $organization = new Organization(
            'members-pagination-' . $suffix,
            'Pagination Organization',
        );

        $em->persist($organization);
        $em->persist(new OrganizationMembership(
            $organization,
            $subject,
            'admin',
        ));
        $em->flush();

        self::login($client, $subject, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $url = '/api/v1/organizations/'
            . $organization->getId()
            . '/members';

        $headers = [
            'HTTP_X_ORGANIZATION_ID' => $organization->getId(),
        ];

        foreach (
            ['page=0', 'page=abc', 'limit=0', 'limit=101']
            as $query
        ) {
            $client->request(
                'GET',
                $url . '?' . $query,
                [],
                [],
                $headers,
            );

            self::assertResponseStatusCodeSame(
                Response::HTTP_BAD_REQUEST,
            );
        }
    }

    /**
     * @param list<string> $roles
     */
    private static function login(
        KernelBrowser $client,
        string $subject,
        array $roles,
    ): void {
        $client->loginUser(
            new OidcUser(
                $subject,
                $subject,
                null,
                $roles,
                time(),
            ),
            'main',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(
        KernelBrowser $client,
    ): array {
        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($data);

        return $data;
    }
}
