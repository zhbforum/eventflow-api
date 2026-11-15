<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\OidcIdTokenVerifier;
use App\Security\OidcProviderFactory;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use OpenSSLAsymmetricKey;
use Psr\Http\Message\RequestInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OidcSuccessfulCallbackIntegrationTest extends WebTestCase
{
    private const ISSUER =
    'http://auth.eventflow.localhost:8081/realms/eventflow';

    public function testCallbackCreatesAuthenticatedSession(): void
    {
        $client = static::createClient();

        $client->disableReboot();

        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        self::assertInstanceOf(
            OpenSSLAsymmetricKey::class,
            $privateKey,
        );

        $details = openssl_pkey_get_details($privateKey);

        self::assertIsArray($details);

        $nonce = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));

        $idToken = JWT::encode(
            [
                'iss' => self::ISSUER,
                'sub' => 'user-123',
                'aud' => 'eventflow-bff',
                'iat' => time(),
                'exp' => time() + 300,
                'nonce' => $nonce,
                'preferred_username' => 'eventflow-admin',
                'email' => 'admin@example.test',
                'realm_access' => [
                    'roles' => ['eventflow-admin'],
                ],
            ],
            $privateKey,
            'RS256',
            'test-key',
        );

        $tokenRequest = null;

        $guzzle = new Client([
            'handler' => new MockHandler([
                // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Guzzle callback signature
                static function (
                    RequestInterface $request,
                ) use (&$tokenRequest, $idToken): Response {
                    $tokenRequest = $request;

                    return new Response(
                        200,
                        ['Content-Type' => 'application/json'],
                        json_encode([
                            'access_token' => 'test-access-token',
                            'token_type' => 'Bearer',
                            'expires_in' => 300,
                            'id_token' => $idToken,
                        ], JSON_THROW_ON_ERROR),
                    );
                },
            ]),
        ]);

        static::getContainer()->set(
            OidcProviderFactory::class,
            new OidcProviderFactory(
                self::ISSUER,
                'eventflow-bff',
                'test-only-client-secret',
                'http://localhost:8000/api/v1/auth/callback',
                $guzzle,
            ),
        );

        $jwks = [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => 'test-key',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => self::base64Url($details['rsa']['n']),
                'e' => self::base64Url($details['rsa']['e']),
            ]],
        ];

        $jwksClient = new MockHttpClient(
            new MockResponse(
                json_encode($jwks, JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            ),
        );

        static::getContainer()->set(
            OidcIdTokenVerifier::class,
            new OidcIdTokenVerifier(
                $jwksClient,
                self::ISSUER,
                'eventflow-bff',
            ),
        );

        $session = $client->getSession();

        $session->set('oidc.state', 'expected-state');
        $session->set('oidc.nonce', $nonce);
        $session->set('oidc.code_verifier', $verifier);
        $session->set('oidc.started_at', time());
        $session->save();

        $client->request(
            'GET',
            '/api/v1/auth/callback',
            [
                'state' => 'expected-state',
                'code' => 'test-authorization-code',
            ],
        );

        self::assertResponseRedirects('/api/v1/auth/me');

        self::assertInstanceOf(
            RequestInterface::class,
            $tokenRequest,
        );

        self::assertSame(
            'POST',
            $tokenRequest->getMethod(),
        );

        self::assertSame(
            self::ISSUER . '/protocol/openid-connect/token',
            (string) $tokenRequest->getUri(),
        );

        parse_str(
            (string) $tokenRequest->getBody(),
            $form,
        );

        self::assertSame(
            'authorization_code',
            $form['grant_type'] ?? null,
        );

        self::assertSame(
            'test-authorization-code',
            $form['code'] ?? null,
        );

        self::assertSame(
            $verifier,
            $form['code_verifier'] ?? null,
        );

        self::assertFalse($client->getSession()->has('oidc.state'));
        self::assertFalse($client->getSession()->has('oidc.nonce'));
        self::assertFalse(
            $client->getSession()->has('oidc.code_verifier'),
        );

        $client->followRedirect();

        self::assertResponseIsSuccessful();

        $body = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('user-123', $body['id']);
        self::assertSame(
            'eventflow-admin',
            $body['username'],
        );

        self::assertContains(
            'ROLE_EVENTFLOW_ADMIN',
            $body['roles'],
        );

        self::assertContains(
            'ROLE_EVENTFLOW_VIEWER',
            $body['roles'],
        );

        self::assertArrayNotHasKey('access_token', $body);
        self::assertArrayNotHasKey('id_token', $body);
        self::assertArrayNotHasKey('refresh_token', $body);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(
            strtr(base64_encode($value), '+/', '-_'),
            '=',
        );
    }
}
