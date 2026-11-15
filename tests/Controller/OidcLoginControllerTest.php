<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class OidcLoginControllerTest extends WebTestCase
{
    public function testLoginRedirectsToKeycloakWithPkce(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/auth/login');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FOUND,
        );

        $location = $client->getResponse()
            ->headers->get('Location');

        self::assertNotNull($location);

        self::assertStringStartsWith(
            'http://auth.eventflow.localhost:8081/'
                . 'realms/eventflow/protocol/openid-connect/auth',
            $location,
        );

        parse_str(
            (string) parse_url($location, PHP_URL_QUERY),
            $parameters,
        );

        self::assertSame(
            'eventflow-bff',
            $parameters['client_id'],
        );

        self::assertSame(
            'code',
            $parameters['response_type'],
        );

        self::assertSame(
            'S256',
            $parameters['code_challenge_method'],
        );

        self::assertNotEmpty($parameters['code_challenge']);
        self::assertNotEmpty($parameters['state']);
        self::assertNotEmpty($parameters['nonce']);

        self::assertContains(
            'openid',
            explode(' ', $parameters['scope']),
        );

        $session = $client->getSession();

        self::assertSame(
            $parameters['state'],
            $session->get('oidc.state'),
        );

        self::assertSame(
            $parameters['nonce'],
            $session->get('oidc.nonce'),
        );

        $verifier = $session->get('oidc.code_verifier');

        self::assertIsString($verifier);
        self::assertNotEmpty($verifier);

        $expectedChallenge = rtrim(
            strtr(
                base64_encode(hash('sha256', $verifier, true)),
                '+/',
                '-_',
            ),
            '=',
        );

        self::assertSame(
            $expectedChallenge,
            $parameters['code_challenge'],
        );
    }
}
