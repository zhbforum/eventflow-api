<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class OidcCallbackAuthenticatorTest extends WebTestCase
{
    public function testRejectsCallbackWithoutLoginAttempt(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/auth/callback', [
            'state' => 'unexpected-state',
            'code' => 'unexpected-code',
        ]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
        );

        self::assertSame(
            '{"error":"OIDC authentication failed"}',
            $client->getResponse()->getContent(),
        );
    }

    public function testRejectsWrongState(): void
    {
        $client = static::createClient();

        self::prepareSession($client, time());

        $client->request('GET', '/api/v1/auth/callback', [
            'state' => 'attacker-state',
            'code' => 'test-code',
        ]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
        );

        self::assertFalse(
            $client->getSession()->has('oidc.state'),
        );
    }

    public function testRejectsExpiredLoginAttempt(): void
    {
        $client = static::createClient();

        self::prepareSession($client, time() - 301);

        $client->request('GET', '/api/v1/auth/callback', [
            'state' => 'expected-state',
            'code' => 'test-code',
        ]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
        );

        self::assertFalse(
            $client->getSession()->has('oidc.code_verifier'),
        );
    }

    public function testRejectsRepeatedCallback(): void
    {
        $client = static::createClient();

        self::prepareSession($client, time());

        $parameters = [
            'state' => 'wrong-state',
            'code' => 'test-code',
        ];

        $client->request(
            'GET',
            '/api/v1/auth/callback',
            $parameters,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
        );

        self::assertFalse(
            $client->getSession()->has('oidc.state'),
        );

        $client->request(
            'GET',
            '/api/v1/auth/callback',
            $parameters,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
        );
    }

    public function testRejectsKeycloakError(): void
    {
        $client = static::createClient();

        self::prepareSession($client, time());

        $client->request('GET', '/api/v1/auth/callback', [
            'state' => 'expected-state',
            'error' => 'access_denied',
        ]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNAUTHORIZED,
        );
    }

    private static function prepareSession(
        KernelBrowser $client,
        int $startedAt,
    ): void {
        $session = $client->getSession();

        $session->set('oidc.state', 'expected-state');
        $session->set('oidc.nonce', 'expected-nonce');
        $session->set(
            'oidc.code_verifier',
            str_repeat('a', 64),
        );
        $session->set('oidc.started_at', $startedAt);

        $session->save();
    }
}
