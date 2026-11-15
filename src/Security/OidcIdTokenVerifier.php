<?php

declare(strict_types=1);

namespace App\Security;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;
use stdClass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class OidcIdTokenVerifier
{
    public function __construct(
        private HttpClientInterface $httpClient,

        #[Autowire('%env(default::OIDC_ISSUER)%')]
        private ?string $issuer,

        #[Autowire('%env(default::OIDC_CLIENT_ID)%')]
        private ?string $clientId,

        #[Autowire('%env(default::OIDC_BACKCHANNEL_BASE_URL)%')]
        private ?string $backchannelBaseUrl = null,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     username: string,
     *     email: ?string,
     *     roles: list<string>
     * }
     */
    public function verify(
        string $idToken,
        string $expectedNonce,
    ): array {
        if (!$this->issuer || !$this->clientId) {
            throw new RuntimeException(
                'OIDC configuration is incomplete',
            );
        }

        try {
            $issuer = rtrim($this->issuer, '/');

            $backchannel = rtrim(
                $this->backchannelBaseUrl ?: $issuer,
                '/',
            );

            $response = $this->httpClient->request(
                'GET',
                $backchannel . '/protocol/openid-connect/certs',
                [
                    'timeout' => 5,
                    'max_duration' => 10,
                    'max_redirects' => 0,
                ],
            );

            $jwks = $response->toArray();
            $keys = $jwks['keys'] ?? null;

            if (!is_array($keys)) {
                throw new RuntimeException('Invalid JWKS');
            }

            $signingKeys = [];

            foreach ($keys as $key) {
                if (
                    !is_array($key)
                    || ($key['kty'] ?? null) !== 'RSA'
                    || ($key['use'] ?? 'sig') !== 'sig'
                    || ($key['alg'] ?? 'RS256') !== 'RS256'
                ) {
                    continue;
                }

                $signingKeys[] = $key;
            }

            if ($signingKeys === []) {
                throw new RuntimeException(
                    'No trusted signing keys',
                );
            }

            $claims = JWT::decode(
                $idToken,
                JWK::parseKeySet(
                    ['keys' => $signingKeys],
                    'RS256',
                ),
            );

            if ($claims->iss !== $issuer) {
                throw new RuntimeException('Invalid issuer');
            }

            $audiences = $claims->aud ?? null;

            if (is_string($audiences)) {
                $audiences = [$audiences];
            }

            if (
                !is_array($audiences)
                || !in_array($this->clientId, $audiences, true)
            ) {
                throw new RuntimeException('Invalid audience');
            }

            $azp = $claims->azp ?? null;

            if (
                (count($audiences) > 1 && $azp !== $this->clientId)
                || ($azp !== null && $azp !== $this->clientId)
            ) {
                throw new RuntimeException(
                    'Invalid authorized party',
                );
            }

            if (
                !isset($claims->nonce)
                || !is_string($claims->nonce)
                || !hash_equals($expectedNonce, $claims->nonce)
            ) {
                throw new RuntimeException('Invalid nonce');
            }

            if (
                !isset($claims->sub, $claims->exp, $claims->iat)
                || !is_string($claims->sub)
                || $claims->sub === ''
                || !is_int($claims->exp)
                || !is_int($claims->iat)
                || $claims->iat > $claims->exp
            ) {
                throw new RuntimeException(
                    'Invalid required OIDC claims',
                );
            }

            $username = $claims->preferred_username ?? null;

            if (!is_string($username) || $username === '') {
                $username = $claims->sub;
            }

            $email = $claims->email ?? null;

            if (!is_string($email)) {
                $email = null;
            }

            $realmAccess = $claims->realm_access ?? null;

            $rawRoles = $realmAccess instanceof stdClass
                ? ($realmAccess->roles ?? [])
                : [];

            if (!is_array($rawRoles)) {
                $rawRoles = [];
            }

            $roles = array_values(array_filter(
                $rawRoles,
                static fn(mixed $role): bool => is_string($role),
            ));

            return [
                'id' => $claims->sub,
                'username' => $username,
                'email' => $email,
                'roles' => $roles,
            ];
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Invalid OIDC ID token',
                0,
                $exception,
            );
        }
    }
}
