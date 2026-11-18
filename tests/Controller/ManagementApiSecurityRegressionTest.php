<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WebhookEndpoint;
use App\Security\OidcUser;
use App\Tests\Fixtures\LegacyOrganizationTestContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ManagementApiSecurityRegressionTest extends WebTestCase
{
    private const MISSING_ID =
    '00000000-0000-7000-8000-000000000000';

    public function testUserWithoutEventFlowRoleCannotAccessManagementApi(): void
    {
        $client = self::authenticatedClient(['ROLE_USER']);

        $client->request('GET', '/api/v1/events');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->jsonRequest(
            'POST',
            '/api/v1/webhooks',
            [],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testAdminWithInvalidCsrfCannotModifyWebhook(): void
    {
        $client = self::authenticatedClient([
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
            'ROLE_EVENTFLOW_VIEWER',
        ]);

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $webhook = new WebhookEndpoint(
            'https://example.com/original',
            ['order.created'],
        );

        $entityManager->persist($webhook);
        $entityManager->flush();

        $client->jsonRequest(
            'PATCH',
            '/api/v1/webhooks/' . $webhook->getId(),
            ['isActive' => false],
            ['HTTP_X_CSRF_TOKEN' => 'invalid-token'],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $entityManager->refresh($webhook);

        self::assertTrue($webhook->isActive());
        self::assertSame(
            'https://example.com/original',
            $webhook->getUrl(),
        );
    }

    public function testViewerCannotWriteEvenWithValidCsrf(): void
    {
        $client = self::authenticatedClient([
            'ROLE_USER',
            'ROLE_EVENTFLOW_VIEWER',
        ]);

        $client->request('GET', '/api/v1/auth/csrf');

        self::assertResponseIsSuccessful();

        $data = self::decodeResponse($client);

        $token = $data['management_csrf_token'] ?? null;

        self::assertIsString($token);

        $client->jsonRequest(
            'PATCH',
            '/api/v1/webhooks/' . self::MISSING_ID,
            ['isActive' => false],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testLogoutRevokesManagementSession(): void
    {
        $client = self::authenticatedClient([
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
            'ROLE_EVENTFLOW_VIEWER',
        ]);

        $client->request('GET', '/api/v1/events');

        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/auth/csrf');

        self::assertResponseIsSuccessful();

        $data = self::decodeResponse($client);

        $logoutToken = $data['logout_csrf_token'] ?? null;
        $managementToken = $data['management_csrf_token'] ?? null;

        self::assertIsString($logoutToken);
        self::assertIsString($managementToken);

        $client->request(
            'POST',
            '/api/v1/auth/logout',
            ['_csrf_token' => $logoutToken],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_SEE_OTHER,
        );

        $client->request('GET', '/api/v1/events');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->jsonRequest(
            'PATCH',
            '/api/v1/webhooks/' . self::MISSING_ID,
            ['isActive' => false],
            ['HTTP_X_CSRF_TOKEN' => $managementToken],
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
        $subject = 'security-regression-' . bin2hex(random_bytes(8));

        LegacyOrganizationTestContext::enable(
            $client,
            static::getContainer()->get(EntityManagerInterface::class),
            $subject,
            in_array('ROLE_EVENTFLOW_ADMIN', $roles, true)
                ? 'admin'
                : 'viewer',
        );

        $client->loginUser(
            new OidcUser(
                $subject,
                'security-user',
                'security@example.test',
                $roles,
                time(),
            ),
            'main',
        );

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeResponse(
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
