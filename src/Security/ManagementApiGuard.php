<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class ManagementApiGuard
{
    public function __construct(
        #[Autowire('%env(default::MANAGEMENT_API_TOKEN)%')]
        private ?string $token,
        private Security $security,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {}

    public function check(Request $request): ?JsonResponse
    {
        $authorization = $request->headers->get(
            'Authorization',
            '',
        );

        if ($authorization !== '') {
            return $this->checkBearer($authorization);
        }

        $user = $this->security->getUser();

        if ($user instanceof OidcUser) {
            return $this->checkSession($request, $user);
        }

        if ($this->token === null || $this->token === '') {
            return self::deny('Not found', Response::HTTP_NOT_FOUND);
        }

        return self::deny('Forbidden', Response::HTTP_FORBIDDEN);
    }

    private function checkBearer(string $authorization): ?JsonResponse
    {
        if ($this->token === null || $this->token === '') {
            return self::deny('Not found', Response::HTTP_NOT_FOUND);
        }

        $prefix = 'Bearer ';

        if (
            !str_starts_with($authorization, $prefix)
            || !hash_equals(
                $this->token,
                substr($authorization, strlen($prefix)),
            )
        ) {
            return self::deny('Forbidden', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function checkSession(
        Request $request,
        OidcUser $user,
    ): ?JsonResponse {
        $roles = $user->getRoles();

        $isAdmin = in_array(
            'ROLE_EVENTFLOW_ADMIN',
            $roles,
            true,
        );

        $isViewer = in_array(
            'ROLE_EVENTFLOW_VIEWER',
            $roles,
            true,
        );

        if ($request->isMethod('GET')) {
            if ($isAdmin || $isViewer) {
                return null;
            }

            return self::deny('Forbidden', Response::HTTP_FORBIDDEN);
        }

        if (
            !$request->isMethod('POST')
            && !$request->isMethod('PATCH')
            && !$request->isMethod('DELETE')
            && !$request->isMethod('PUT')
        ) {
            return self::deny('Forbidden', Response::HTTP_FORBIDDEN);
        }

        if (!$isAdmin) {
            return self::deny('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $value = $request->headers->get('X-CSRF-Token', '');

        if (
            $value === ''
            || !$this->csrfTokenManager->isTokenValid(
                new CsrfToken('management_write', $value),
            )
        ) {
            return self::deny(
                'Invalid CSRF token',
                Response::HTTP_FORBIDDEN,
            );
        }

        return null;
    }

    private static function deny(
        string $message,
        int $status,
    ): JsonResponse {
        return new JsonResponse(
            ['error' => $message],
            $status,
            ['Cache-Control' => 'no-store'],
        );
    }
}
