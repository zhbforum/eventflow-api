<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Security\OidcUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class OidcLogoutControllerTest extends WebTestCase
{
    private const ISSUER =
    'http://auth.eventflow.localhost:8081/realms/eventflow';

    public function testAnonymousUserCannotGetCsrfToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/auth/csrf');

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
        );
    }

    public function testLogoutWithoutCsrfIsRejected(): void
    {
        $client = self::authenticatedClient();

        $client->request('POST', '/api/v1/auth/logout');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseIsSuccessful();
    }

    public function testLogoutWithInvalidCsrfIsRejected(): void
    {
        $client = self::authenticatedClient();

        $client->request(
            'POST',
            '/api/v1/auth/logout',
            [
                '_csrf_token' => 'invalid-token',
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseIsSuccessful();
    }

    public function testValidLogoutInvalidatesSession(): void
    {
        $client = self::authenticatedClient();

        $client->request('GET', '/api/v1/auth/csrf');

        self::assertResponseIsSuccessful();

        $body = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($body);

        $csrfToken = $body['logout_csrf_token'] ?? null;

        self::assertIsString($csrfToken);
        self::assertNotEmpty($csrfToken);

        $client->request(
            'POST',
            '/api/v1/auth/logout',
            [
                '_csrf_token' => $csrfToken,
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_SEE_OTHER,
        );

        $location = $client->getResponse()
            ->headers->get('Location');

        self::assertNotNull($location);

        self::assertStringStartsWith(
            self::ISSUER
                . '/protocol/openid-connect/logout?',
            $location,
        );

        parse_str(
            (string) parse_url($location, PHP_URL_QUERY),
            $parameters,
        );

        self::assertSame(
            'eventflow-bff',
            $parameters['client_id'] ?? null,
        );

        self::assertSame(
            'http://localhost:8000/api/v1/auth/logged-out',
            $parameters['post_logout_redirect_uri'] ?? null,
        );

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
        );
    }

    private static function authenticatedClient(): KernelBrowser
    {
        $client = static::createClient();

        $user = new OidcUser(
            'user-123',
            'eventflow-admin',
            'admin@example.test',
            [
                'ROLE_USER',
                'ROLE_EVENTFLOW_ADMIN',
                'ROLE_EVENTFLOW_VIEWER',
            ],
            time(),
        );

        $client->loginUser($user, 'main');

        return $client;
    }
}
