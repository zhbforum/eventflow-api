<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Throwable;

final class OidcCallbackAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly OidcProviderFactory $providerFactory,
        private readonly OidcIdTokenVerifier $tokenVerifier,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_route')
            === 'api_auth_callback';
    }

    public function authenticate(Request $request): Passport
    {
        $session = $request->getSession();

        $expectedState = $session->get('oidc.state');
        $expectedNonce = $session->get('oidc.nonce');
        $codeVerifier = $session->get('oidc.code_verifier');
        $startedAt = $session->get('oidc.started_at');

        foreach (
            [
                'oidc.state',
                'oidc.nonce',
                'oidc.code_verifier',
                'oidc.started_at',
            ] as $key
        ) {
            $session->remove($key);
        }

        $params = $request->query->all();

        $state = $params['state'] ?? null;
        $code = $params['code'] ?? null;

        $now = time();

        $validState = is_string($expectedState)
            && $expectedState !== ''
            && is_string($state)
            && $state !== ''
            && hash_equals($expectedState, $state);

        $validNonce = is_string($expectedNonce)
            && $expectedNonce !== '';

        $validVerifier = is_string($codeVerifier)
            && $codeVerifier !== '';

        $validAttempt = is_int($startedAt)
            && $startedAt <= $now
            && $now - $startedAt <= 300;

        $validCode = is_string($code)
            && $code !== '';

        if (
            !$validState
            || !$validNonce
            || !$validVerifier
            || !$validAttempt
            || !$validCode
            || isset($params['error'])
        ) {
            throw new CustomUserMessageAuthenticationException(
                'OIDC authentication failed',
            );
        }

        try {
            $provider = $this->providerFactory->create();

            $provider->setPkceCode($codeVerifier);

            $accessToken = $provider->getAccessToken(
                'authorization_code',
                [
                    'code' => $code,
                ],
            );

            $values = $accessToken->getValues();
            $idToken = $values['id_token'] ?? null;

            if (
                !is_string($idToken)
                || $idToken === ''
            ) {
                throw new RuntimeException(
                    'Missing ID token',
                );
            }

            $identity = $this->tokenVerifier->verify(
                $idToken,
                $expectedNonce,
            );

            $roles = ['ROLE_USER'];

            if (
                in_array(
                    'eventflow-viewer',
                    $identity['roles'],
                    true,
                )
            ) {
                $roles[] = 'ROLE_EVENTFLOW_VIEWER';
            }

            if (
                in_array(
                    'eventflow-admin',
                    $identity['roles'],
                    true,
                )
            ) {
                $roles[] = 'ROLE_EVENTFLOW_ADMIN';
                $roles[] = 'ROLE_EVENTFLOW_VIEWER';
            }

            if (
                !in_array(
                    'ROLE_EVENTFLOW_VIEWER',
                    $roles,
                    true,
                )
            ) {
                throw new RuntimeException(
                    'EventFlow role required',
                );
            }

            $user = new OidcUser(
                $identity['id'],
                $identity['username'],
                $identity['email'],
                array_values(array_unique($roles)),
                time(),
            );

            return new SelfValidatingPassport(
                new UserBadge(
                    $user->getUserIdentifier(),
                    static fn(): OidcUser => $user,
                ),
            );
        } catch (Throwable) {
            throw new CustomUserMessageAuthenticationException(
                'OIDC authentication failed',
            );
        }
    }

    // phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
    public function onAuthenticationSuccess(
        Request $request,
        TokenInterface $token,
        string $firewallName,
    ): RedirectResponse {
        return new RedirectResponse(
            '/api/v1/auth/me',
        );
    }

    public function onAuthenticationFailure(
        Request $request,
        AuthenticationException $exception,
    ): JsonResponse {
        return new JsonResponse(
            [
                'error' => 'OIDC authentication failed',
            ],
            Response::HTTP_UNAUTHORIZED,
            [
                'Cache-Control' => 'no-store',
            ],
        );
    }

    // phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
}
