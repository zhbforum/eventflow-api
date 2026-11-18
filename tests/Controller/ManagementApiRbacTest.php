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

final class ManagementApiRbacTest extends WebTestCase
{
    private const MISSING_ID =
    '00000000-0000-7000-8000-000000000000';

    private const BEARER = [
        'HTTP_AUTHORIZATION' =>
        'Bearer eventflow-test-management-token',
    ];

    public function testAnonymousUserCannotReadManagementData(): void
    {
        $client = static::createClient();

        foreach (
            [
                '/api/v1/events',
                '/api/v1/events/' . self::MISSING_ID,
                '/api/v1/webhooks',
                '/api/v1/webhooks/' . self::MISSING_ID,
                '/api/v1/deliveries',
                '/api/v1/deliveries/' . self::MISSING_ID,
            ] as $url
        ) {
            $client->request('GET', $url);

            self::assertResponseStatusCodeSame(
                Response::HTTP_FORBIDDEN,
            );
        }
    }

    public function testAnonymousCreateIsDeniedBeforeValidation(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/v1/events', []);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testViewerCanReadButCannotWrite(): void
    {
        $client = self::authenticatedClient([
            'ROLE_USER',
            'ROLE_EVENTFLOW_VIEWER',
        ]);

        $client->request('GET', '/api/v1/events');

        self::assertResponseIsSuccessful();

        $client->jsonRequest(
            'POST',
            '/api/v1/webhooks',
            [],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testAdminRequiresCsrfForWrite(): void
    {
        $client = self::authenticatedClient([
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
            'ROLE_EVENTFLOW_VIEWER',
        ]);

        $url = '/api/v1/webhooks/' . self::MISSING_ID;

        $client->jsonRequest(
            'PATCH',
            $url,
            ['isActive' => false],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request('GET', '/api/v1/auth/csrf');

        self::assertResponseIsSuccessful();

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($data);

        $csrfToken = $data['management_csrf_token'] ?? null;

        self::assertIsString($csrfToken);
        self::assertNotEmpty($csrfToken);

        $client->jsonRequest(
            'PATCH',
            $url,
            ['isActive' => false],
            ['HTTP_X_CSRF_TOKEN' => $csrfToken],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );
    }

    public function testBearerCanReadAndWriteWithoutSessionCsrf(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/api/v1/events',
            [],
            [],
            self::BEARER,
        );

        self::assertResponseIsSuccessful();

        $client->jsonRequest(
            'PATCH',
            '/api/v1/webhooks/' . self::MISSING_ID,
            ['isActive' => false],
            self::BEARER,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );
    }

    public function testInvalidBearerDoesNotFallBackToSession(): void
    {
        $client = self::authenticatedClient([
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
            'ROLE_EVENTFLOW_VIEWER',
        ]);

        $client->request(
            'GET',
            '/api/v1/events',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer wrong-token'],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    /**
     * @param list<string> $roles
     */
    private static function authenticatedClient(
        array $roles,
    ): KernelBrowser {
        $client = static::createClient();

        $subject = 'rbac-' . bin2hex(random_bytes(8));

        $em = static::getContainer()->get(EntityManagerInterface::class);

        $organization = $em->find(
            Organization::class,
            Organization::LEGACY_ID,
        );

        self::assertInstanceOf(Organization::class, $organization);

        $em->persist(new OrganizationMembership(
            $organization,
            $subject,
            in_array('ROLE_EVENTFLOW_ADMIN', $roles, true)
                ? 'admin'
                : 'viewer',
        ));
        $em->flush();

        $client->setServerParameter(
            'HTTP_X_ORGANIZATION_ID',
            Organization::LEGACY_ID,
        );

        $client->loginUser(
            new OidcUser(
                $subject,
                'test-user',
                'test@example.test',
                $roles,
                time(),
            ),
            'main',
        );

        return $client;
    }
}
