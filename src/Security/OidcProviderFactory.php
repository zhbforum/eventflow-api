<?php

declare(strict_types=1);

namespace App\Security;

use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Provider\GenericProvider;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class OidcProviderFactory
{
    public function __construct(
        #[Autowire('%env(default::OIDC_ISSUER)%')]
        private ?string $issuer,

        #[Autowire('%env(default::OIDC_CLIENT_ID)%')]
        private ?string $clientId,

        #[Autowire('%env(default::OIDC_CLIENT_SECRET)%')]
        private ?string $clientSecret,

        #[Autowire('%env(default::OIDC_REDIRECT_URI)%')]
        private ?string $redirectUri,

        private ?ClientInterface $httpClient = null,

        #[Autowire('%env(default::OIDC_BACKCHANNEL_BASE_URL)%')]
        private ?string $backchannelBaseUrl = null,
    ) {}

    public function create(): GenericProvider
    {
        if (
            !$this->issuer
            || !$this->clientId
            || !$this->clientSecret
            || !$this->redirectUri
        ) {
            throw new RuntimeException(
                'OIDC configuration is incomplete',
            );
        }

        $issuer = rtrim($this->issuer, '/');

        $backchannel = rtrim(
            $this->backchannelBaseUrl ?: $issuer,
            '/',
        );

        $provider = new GenericProvider([
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
            'redirectUri' => $this->redirectUri,

            'urlAuthorize' =>
            $issuer . '/protocol/openid-connect/auth',

            'urlAccessToken' =>
            $backchannel . '/protocol/openid-connect/token',

            'urlResourceOwnerDetails' =>
            $backchannel . '/protocol/openid-connect/userinfo',

            'pkceMethod' =>
            GenericProvider::PKCE_METHOD_S256,
        ]);

        if ($this->httpClient !== null) {
            $provider->setHttpClient($this->httpClient);
        }

        return $provider;
    }
}
