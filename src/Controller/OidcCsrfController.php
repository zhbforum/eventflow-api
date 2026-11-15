<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\OidcUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final readonly class OidcCsrfController
{
    #[Route(
        '/api/v1/auth/csrf',
        name: 'api_auth_csrf',
        methods: ['GET'],
        format: 'json',
    )]
    public function __invoke(
        Security $security,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): JsonResponse {
        if (!$security->getUser() instanceof OidcUser) {
            return new JsonResponse(
                ['error' => 'Unauthenticated'],
                Response::HTTP_UNAUTHORIZED,
                ['Cache-Control' => 'no-store'],
            );
        }

        return new JsonResponse(
            [
                'logout_csrf_token' =>
                $csrfTokenManager
                    ->getToken('logout')
                    ->getValue(),
                'management_csrf_token' =>
                $csrfTokenManager
                    ->getToken('management_write')
                    ->getValue(),
            ],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }
}
