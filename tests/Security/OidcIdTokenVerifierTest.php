<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\OidcIdTokenVerifier;
use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OidcIdTokenVerifierTest extends TestCase
{
    private const ISSUER =
    'http://auth.eventflow.localhost:8081/realms/eventflow';

    private OpenSSLAsymmetricKey $privateKey;

    private array $jwks;

    protected function setUp(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        $this->privateKey = $key;

        $details = openssl_pkey_get_details($key);

        self::assertIsArray($details);

        $this->jwks = [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => 'test-key',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => self::base64Url($details['rsa']['n']),
                'e' => self::base64Url($details['rsa']['e']),
            ]],
        ];
    }

    public function testAcceptsValidIdToken(): void
    {
        $identity = $this->verifier()->verify(
            $this->token(),
            'expected-nonce',
        );

        self::assertSame('user-123', $identity['id']);
        self::assertSame('eventflow-admin', $identity['username']);
        self::assertSame('admin@example.test', $identity['email']);

        self::assertContains(
            'eventflow-admin',
            $identity['roles'],
        );
    }

    public function testRejectsInvalidNonce(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Invalid OIDC ID token');

        $this->verifier()->verify(
            $this->token(),
            'wrong-nonce',
        );
    }

    public function testRejectsWrongAudience(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Invalid OIDC ID token');

        $this->verifier()->verify(
            $this->token(['aud' => 'another-client']),
            'expected-nonce',
        );
    }

    public function testRejectsWrongIssuer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Invalid OIDC ID token');

        $this->verifier()->verify(
            $this->token(['iss' => 'https://evil.example']),
            'expected-nonce',
        );
    }

    public function testRejectsExpiredToken(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Invalid OIDC ID token');

        $this->verifier()->verify(
            $this->token([
                'iat' => time() - 3600,
                'exp' => time() - 60,
            ]),
            'expected-nonce',
        );
    }

    private function verifier(): OidcIdTokenVerifier
    {
        $json = json_encode(
            $this->jwks,
            JSON_THROW_ON_ERROR,
        );

        $client = new MockHttpClient(
            new MockResponse($json, ['http_code' => 200]),
        );

        return new OidcIdTokenVerifier(
            $client,
            self::ISSUER,
            'eventflow-bff',
        );
    }

    private function token(array $overrides = []): string
    {
        $claims = array_replace([
            'iss' => self::ISSUER,
            'sub' => 'user-123',
            'aud' => 'eventflow-bff',
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => 'expected-nonce',
            'preferred_username' => 'eventflow-admin',
            'email' => 'admin@example.test',
            'realm_access' => [
                'roles' => ['eventflow-admin'],
            ],
        ], $overrides);

        return JWT::encode(
            $claims,
            $this->privateKey,
            'RS256',
            'test-key',
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
