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

final class OrganizationCreateControllerTest extends WebTestCase
{
    public function testAnonymousAndBearerCannotCreate(): void
    {
        $client = static::createClient();

        $body = [
            'slug' => 'blocked-org',
            'name' => 'Blocked Organization',
        ];

        $client->jsonRequest(
            'POST',
            '/api/v1/organizations',
            $body,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->jsonRequest(
            'POST',
            '/api/v1/organizations',
            $body,
            [
                'HTTP_AUTHORIZATION' =>
                'Bearer eventflow-test-management-token',
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testGlobalAdminRequiresCsrf(): void
    {
        $client = static::createClient();

        $subject = 'org-csrf-' . bin2hex(random_bytes(8));

        self::login($client, $subject, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $body = [
            'slug' => 'csrf-' . bin2hex(random_bytes(8)),
            'name' => 'Protected Organization',
        ];

        $client->jsonRequest(
            'POST',
            '/api/v1/organizations',
            $body,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->jsonRequest(
            'POST',
            '/api/v1/organizations',
            $body,
            ['HTTP_X_CSRF_TOKEN' => 'invalid'],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testGlobalViewerCannotCreate(): void
    {
        $client = static::createClient();

        self::login(
            $client,
            'org-viewer-' . bin2hex(random_bytes(8)),
            ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
        );

        $csrf = self::csrf($client);

        $client->jsonRequest(
            'POST',
            '/api/v1/organizations',
            [
                'slug' => 'viewer-' . bin2hex(random_bytes(8)),
                'name' => 'Forbidden Organization',
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testAdminCreatesOrganizationAndMembership(): void
    {
        $client = static::createClient();

        $suffix = bin2hex(random_bytes(8));
        $subject = 'org-owner-' . $suffix;
        $slug = 'new-org-' . $suffix;

        self::login($client, $subject, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $csrf = self::csrf($client);

        $client->jsonRequest(
            'POST',
            '/api/v1/organizations',
            [
                'slug' => $slug,
                'name' => 'New Organization',
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        $body = self::decode($client);

        self::assertSame($slug, $body['slug']);
        self::assertSame('New Organization', $body['name']);
        self::assertSame('admin', $body['role']);
        self::assertTrue($body['canManage']);

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $organization = $em->find(
            Organization::class,
            $body['id'],
        );

        self::assertInstanceOf(
            Organization::class,
            $organization,
        );

        $membership = $em
            ->getRepository(OrganizationMembership::class)
            ->findOneBy([
                'organization' => $organization,
                'oidcSubject' => $subject,
            ]);

        self::assertInstanceOf(
            OrganizationMembership::class,
            $membership,
        );

        self::assertTrue($membership->isAdmin());

        $client->request(
            'GET',
            '/api/v1/organizations',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $list = self::decode($client);

        self::assertSame(1, $list['pagination']['total']);
        self::assertSame(
            $organization->getId(),
            $list['items'][0]['id'],
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

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testRejectsInvalidPayloadAndDuplicateSlug(): void
    {
        $client = static::createClient();

        $suffix = bin2hex(random_bytes(8));

        self::login(
            $client,
            'org-validation-' . $suffix,
            ['ROLE_USER', 'ROLE_EVENTFLOW_ADMIN'],
        );

        $csrf = self::csrf($client);
        $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];

        $client->jsonRequest(
            'POST',
            '/api/v1/organizations',
            [
                'slug' => 'INVALID SLUG',
                'name' => 'Invalid Organization',
            ],
            $headers,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        $client->jsonRequest(
            'POST',
            '/api/v1/organizations',
            [
                'slug' => 'valid-' . $suffix,
                'name' => 'Valid Organization',
                'ownerId' => 'another-user',
            ],
            $headers,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_BAD_REQUEST,
        );

        $slug = 'duplicate-' . $suffix;

        $client->jsonRequest(
            'POST',
            '/api/v1/organizations',
            [
                'slug' => $slug,
                'name' => 'First Organization',
            ],
            $headers,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        $client->jsonRequest(
            'POST',
            '/api/v1/organizations',
            [
                'slug' => $slug,
                'name' => 'Second Organization',
            ],
            $headers,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CONFLICT,
        );

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        self::assertCount(
            1,
            $em->getRepository(Organization::class)
                ->findBy(['slug' => $slug]),
        );
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

    private static function csrf(KernelBrowser $client): string
    {
        $client->request('GET', '/api/v1/auth/csrf');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = self::decode($client);

        $token = $data['management_csrf_token'] ?? null;

        self::assertIsString($token);
        self::assertNotEmpty($token);

        return $token;
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
