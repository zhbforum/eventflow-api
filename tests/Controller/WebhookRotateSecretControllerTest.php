<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WebhookEndpoint;
use App\Security\OidcUser;
use App\Tests\Fixtures\LegacyOrganizationTestContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class WebhookRotateSecretControllerTest extends WebTestCase
{
    private const BEARER = [
        'HTTP_AUTHORIZATION' =>
        'Bearer eventflow-test-management-token',
    ];

    public function testRotatesAndPersistsSigningSecret(): void
    {
        $client = static::createClient();

        $em = static::getContainer()
            ->get(EntityManagerInterface::class);

        $endpoint = new WebhookEndpoint(
            'https://example.com/hooks',
            ['order.created'],
        );

        $em->persist($endpoint);
        $em->flush();

        $id = $endpoint->getId();
        $originalSecret = $endpoint->getSigningSecret();

        $client->request(
            'POST',
            '/api/v1/webhooks/' . $id . '/rotate-secret',
            [],
            [],
            self::BEARER,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($id, $data['id']);

        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $data['signingSecret'],
        );

        self::assertNotSame(
            $originalSecret,
            $data['signingSecret'],
        );

        self::assertTrue(
            $client->getResponse()
                ->headers
                ->hasCacheControlDirective('no-store'),
        );

        $em->refresh($endpoint);

        self::assertSame(
            $data['signingSecret'],
            $endpoint->getSigningSecret(),
        );

        $client->request(
            'GET',
            '/api/v1/webhooks/' . $id,
            [],
            [],
            self::BEARER,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $retrieved = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertArrayNotHasKey(
            'signingSecret',
            $retrieved,
        );
    }

    public function testAnonymousRotationIsForbidden(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/webhooks/'
                . '00000000-0000-7000-8000-000000000000'
                . '/rotate-secret',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testInvalidBearerTokenIsForbidden(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/webhooks/'
                . '00000000-0000-7000-8000-000000000000'
                . '/rotate-secret',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer invalid-token',
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testRotationOfNonexistentWebhookReturns404(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/webhooks/'
                . '00000000-0000-7000-8000-000000000000'
                . '/rotate-secret',
            [],
            [],
            self::BEARER,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );
    }

    public function testViewerCannotRotateSecret(): void
    {
        $client = static::createClient();

        $client->loginUser(
            new OidcUser(
                'viewer-test-user',
                'viewer',
                'viewer@example.test',
                [
                    'ROLE_USER',
                    'ROLE_EVENTFLOW_VIEWER',
                ],
                time(),
            ),
            'main',
        );

        LegacyOrganizationTestContext::enable(
            $client,
            static::getContainer()->get(EntityManagerInterface::class),
            'viewer-test-user',
            'viewer',
        );

        $client->request(
            'POST',
            '/api/v1/webhooks/'
                . '00000000-0000-7000-8000-000000000000'
                . '/rotate-secret',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testAdminRequiresCsrfToken(): void
    {
        $client = static::createClient();

        $client->loginUser(
            new OidcUser(
                'admin-test-user',
                'admin',
                'admin@example.test',
                [
                    'ROLE_USER',
                    'ROLE_EVENTFLOW_ADMIN',
                ],
                time(),
            ),
            'main',
        );

        LegacyOrganizationTestContext::enable(
            $client,
            static::getContainer()->get(EntityManagerInterface::class),
            'admin-test-user',
            'admin',
        );

        $url = '/api/v1/webhooks/'
            . '00000000-0000-7000-8000-000000000000'
            . '/rotate-secret';

        $client->request('POST', $url);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request(
            'GET',
            '/api/v1/auth/csrf',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $csrfToken = $data['management_csrf_token'];

        $client->request(
            'POST',
            $url,
            [],
            [],
            [
                'HTTP_X_CSRF_TOKEN' => $csrfToken,
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );
    }
}
