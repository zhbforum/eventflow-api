<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class OrganizationListControllerTest extends WebTestCase
{
    public function testAnonymousAndBearerAccessAreDenied(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/organizations');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request(
            'GET',
            '/api/v1/organizations',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' =>
                'Bearer eventflow-test-management-token',
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testListsOnlyOwnOrganizationsAndSupportsPagination(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));
        $subject = 'org-list-user-' . $suffix;

        $organizationA = new Organization(
            'org-list-a-' . $suffix,
            'Alpha Organization',
        );

        $organizationB = new Organization(
            'org-list-b-' . $suffix,
            'Beta Organization',
        );

        $foreignOrganization = new Organization(
            'org-list-foreign-' . $suffix,
            'Foreign Organization',
        );

        foreach (
            [
                $organizationA,
                $organizationB,
                $foreignOrganization,
                new OrganizationMembership(
                    $organizationA,
                    $subject,
                    'admin',
                ),
                new OrganizationMembership(
                    $organizationB,
                    $subject,
                    'viewer',
                ),
                new OrganizationMembership(
                    $foreignOrganization,
                    'another-user-' . $suffix,
                    'admin',
                ),
            ] as $entity
        ) {
            $em->persist($entity);
        }

        $em->flush();

        $client->loginUser(
            new OidcUser(
                $subject,
                $subject,
                null,
                ['ROLE_USER', 'ROLE_EVENTFLOW_ADMIN'],
                time(),
            ),
            'main',
        );

        $client->request('GET', '/api/v1/organizations');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = self::decodeResponse(
            (string) $client->getResponse()->getContent(),
        );

        self::assertSame(2, $data['pagination']['total']);
        self::assertCount(2, $data['items']);

        self::assertSame(
            $organizationA->getId(),
            $data['items'][0]['id'],
        );
        self::assertSame('admin', $data['items'][0]['role']);
        self::assertTrue($data['items'][0]['canManage']);

        self::assertSame(
            $organizationB->getId(),
            $data['items'][1]['id'],
        );
        self::assertSame('viewer', $data['items'][1]['role']);
        self::assertFalse($data['items'][1]['canManage']);

        self::assertSame(
            'org-list-a-' . $suffix,
            $data['items'][0]['slug'],
        );

        self::assertNotContains(
            $foreignOrganization->getId(),
            array_column($data['items'], 'id'),
        );

        $client->request(
            'GET',
            '/api/v1/organizations?page=2&limit=1',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $page = self::decodeResponse(
            (string) $client->getResponse()->getContent(),
        );

        self::assertSame(
            ['page' => 2, 'limit' => 1, 'total' => 2, 'pages' => 2],
            $page['pagination'],
        );

        self::assertCount(1, $page['items']);
        self::assertSame(
            $organizationB->getId(),
            $page['items'][0]['id'],
        );

        $client->request('GET', '/api/v1/events');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testGlobalViewerCannotManageAdminMembership(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));
        $subject = 'org-viewer-' . $suffix;

        $organization = new Organization(
            'org-viewer-' . $suffix,
            'Viewer Organization',
        );

        $em->persist($organization);
        $em->persist(new OrganizationMembership(
            $organization,
            $subject,
            'admin',
        ));
        $em->flush();

        $client->loginUser(
            new OidcUser(
                $subject,
                $subject,
                null,
                ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
                time(),
            ),
            'main',
        );

        $client->request('GET', '/api/v1/organizations');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = self::decodeResponse(
            (string) $client->getResponse()->getContent(),
        );

        self::assertSame(1, $data['pagination']['total']);
        self::assertSame('admin', $data['items'][0]['role']);
        self::assertFalse($data['items'][0]['canManage']);

        $client->loginUser(
            new OidcUser(
                $subject,
                $subject,
                null,
                ['ROLE_USER'],
                time(),
            ),
            'main',
        );

        $client->request('GET', '/api/v1/organizations');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testRevokedMembershipDisappearsImmediately(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));
        $subject = 'org-revoked-' . $suffix;

        $organization = new Organization(
            'org-revoked-' . $suffix,
            'Revoked Organization',
        );

        $membership = new OrganizationMembership(
            $organization,
            $subject,
            'viewer',
        );

        $em->persist($organization);
        $em->persist($membership);
        $em->flush();

        $membershipId = $membership->getId();

        $client->loginUser(
            new OidcUser(
                $subject,
                $subject,
                null,
                ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
                time(),
            ),
            'main',
        );

        $client->request('GET', '/api/v1/organizations');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = self::decodeResponse(
            (string) $client->getResponse()->getContent(),
        );

        self::assertSame(1, $data['pagination']['total']);

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $saved = $em->find(
            OrganizationMembership::class,
            $membershipId,
        );

        self::assertInstanceOf(
            OrganizationMembership::class,
            $saved,
        );

        $em->remove($saved);
        $em->flush();

        $client->request('GET', '/api/v1/organizations');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = self::decodeResponse(
            (string) $client->getResponse()->getContent(),
        );

        self::assertSame([], $data['items']);
        self::assertSame(0, $data['pagination']['total']);
    }

    public function testInvalidPaginationIsRejected(): void
    {
        $client = static::createClient();

        $client->loginUser(
            new OidcUser(
                'pagination-user',
                'pagination-user',
                null,
                ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
                time(),
            ),
            'main',
        );

        foreach (
            [
                'page=0',
                'page=abc',
                'limit=0',
                'limit=101',
            ] as $query
        ) {
            $client->request(
                'GET',
                '/api/v1/organizations?' . $query,
            );

            self::assertResponseStatusCodeSame(
                Response::HTTP_BAD_REQUEST,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeResponse(string $content): array
    {
        $data = json_decode(
            $content,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($data);

        return $data;
    }
}
