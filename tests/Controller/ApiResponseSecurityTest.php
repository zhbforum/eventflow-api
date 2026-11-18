<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponseSecurityTest extends WebTestCase
{
    private const AUTH = [
        'HTTP_AUTHORIZATION' =>
        'Bearer eventflow-test-management-token',
    ];

    public function testSuccessfulApiResponseHasSecurityHeaders(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/api/v1/events',
            [],
            [],
            self::AUTH,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        self::assertSecurityHeaders($client->getResponse());
    }

    public function testForbiddenResponseHasSecurityHeaders(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/events');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        self::assertSecurityHeaders($client->getResponse());
    }

    public function testValidationErrorHasSecurityHeaders(): void
    {
        $client = static::createClient();

        $client->jsonRequest(
            'POST',
            '/api/v1/webhooks',
            [
                'url' => 'http://localhost/hook',
                'subscribedEvents' => ['order.created'],
            ],
            self::AUTH,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        self::assertSecurityHeaders($client->getResponse());
    }

    public function testOidcRedirectHasSecurityHeaders(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/auth/login');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FOUND,
        );

        self::assertSecurityHeaders($client->getResponse());
    }

    public function testSigningSecretResponseIsNotCacheable(): void
    {
        $client = static::createClient();

        $client->jsonRequest(
            'POST',
            '/api/v1/webhooks',
            [
                'url' => 'https://example.com/secure-hook',
                'subscribedEvents' => ['order.created'],
            ],
            self::AUTH,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('signingSecret', $data);

        self::assertSecurityHeaders($client->getResponse());
    }

    private static function assertSecurityHeaders(
        Response $response,
    ): void {
        $headers = $response->headers;

        self::assertTrue(
            $headers->hasCacheControlDirective('no-store'),
        );

        self::assertSame(
            'nosniff',
            $headers->get('X-Content-Type-Options'),
        );

        self::assertSame(
            'DENY',
            $headers->get('X-Frame-Options'),
        );

        self::assertSame(
            'no-referrer',
            $headers->get('Referrer-Policy'),
        );
    }
}
