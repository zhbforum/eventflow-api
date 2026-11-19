<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\OidcIdTokenVerifier;
use App\Security\OidcProviderFactory;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use OpenSSLAsymmetricKey;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class OidcSessionFixationIntegrationTest extends WebTestCase
{
    private const ISSUER =
    'http://auth.eventflow.localhost:8081/realms/eventflow';

    public function testSuccessfulOidcCallbackRotatesSessionId(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        $jwks = [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => 'session-test-key',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => self::base64Url($details['rsa']['n']),
                'e' => self::base64Url($details['rsa']['e']),
            ]],
        ];

        $tokenEndpoint = new MockHandler();

        static::getContainer()->set(
            OidcProviderFactory::class,
            new OidcProviderFactory(
                self::ISSUER,
                'eventflow-bff',
                'test-client-secret',
                'http://localhost:8000/api/v1/auth/callback',
                new Client(['handler' => $tokenEndpoint]),
            ),
        );

        static::getContainer()->set(
            OidcIdTokenVerifier::class,
            new OidcIdTokenVerifier(
                new MockHttpClient(new MockResponse(
                    json_encode($jwks, JSON_THROW_ON_ERROR),
                    ['http_code' => 200],
                )),
                self::ISSUER,
                'eventflow-bff',
            ),
        );

        $client->request('GET', '/api/v1/auth/login');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        $session = $client->getSession();

        $oldSessionId = $session->getId();
        $state = $session->get('oidc.state');
        $nonce = $session->get('oidc.nonce');

        self::assertNotEmpty($oldSessionId);
        self::assertIsString($state);
        self::assertNotEmpty($state);
        self::assertIsString($nonce);
        self::assertNotEmpty($nonce);

        $idToken = JWT::encode([
            'iss' => self::ISSUER,
            'sub' => 'session-security-user',
            'aud' => 'eventflow-bff',
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => $nonce,
            'preferred_username' => 'session-security-user',
            'realm_access' => [
                'roles' => ['eventflow-viewer'],
            ],
        ], $key, 'RS256', 'session-test-key');

        $tokenEndpoint->append(new GuzzleResponse(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'access_token' => 'test-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 300,
                'id_token' => $idToken,
            ], JSON_THROW_ON_ERROR),
        ));

        $client->request(
            'GET',
            '/api/v1/auth/callback',
            [
                'state' => $state,
                'code' => 'test-authorization-code',
            ],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);

        self::assertSame(
            '/',
            $client->getResponse()->headers->get('Location'),
        );

        $newSessionId = $client->getSession()->getId();

        self::assertNotEmpty($newSessionId);

        self::assertNotSame(
            $oldSessionId,
            $newSessionId,
            'Session ID must change after successful authentication.',
        );

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $identity = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'session-security-user',
            $identity['id'],
        );
    }

    private static function base64Url(string $value): string
    {
        return rtrim(
            strtr(base64_encode($value), '+/', '-_'),
            '=',
        );
    }
}
