<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Security\OidcUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class OidcMeControllerTest extends WebTestCase
{
    public function testAnonymousUserReceivesUnauthorized(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
        );


        $response = $client->getResponse();

        self::assertTrue(
            $response->headers->hasCacheControlDirective('no-store'),
            sprintf(
                'Expected no-store cache directive, got: %s',
                $response->headers->get('Cache-Control') ?? '<missing>',
            ),
        );


        $response = $client->getResponse();

        $body = json_decode(
            (string) $response->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ['error' => 'Unauthenticated'],
            $body,
        );
    }


    public function testAuthenticatedUserReceivesIdentity(): void
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

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $body = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('user-123', $body['id']);
        self::assertSame('eventflow-admin', $body['username']);
        self::assertSame('admin@example.test', $body['email']);

        self::assertContains(
            'ROLE_EVENTFLOW_ADMIN',
            $body['roles'],
        );

        self::assertArrayNotHasKey('access_token', $body);
        self::assertArrayNotHasKey('refresh_token', $body);
    }
}
