<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\OidcProviderFactory;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class OidcLoginController
{
    public function __construct(
        private OidcProviderFactory $providerFactory,
    ) {}

    #[Route(
        '/api/v1/auth/login',
        name: 'api_auth_login',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): RedirectResponse
    {
        $provider = $this->providerFactory->create();

        $nonce = bin2hex(random_bytes(32));

        $authorizationUrl = $provider->getAuthorizationUrl([
            'scope' => 'openid profile email',
            'nonce' => $nonce,
        ]);

        $state = $provider->getState();
        $codeVerifier = $provider->getPkceCode();


        if (
            $state === ''
            || !is_string($codeVerifier)
            || $codeVerifier === ''
        ) {
            throw new RuntimeException(
                'Failed to initialize OIDC authorization',
            );
        }


        $session = $request->getSession();

        $session->set('oidc.state', $state);
        $session->set('oidc.nonce', $nonce);
        $session->set('oidc.code_verifier', $codeVerifier);
        $session->set('oidc.started_at', time());

        return new RedirectResponse($authorizationUrl);
    }
}
