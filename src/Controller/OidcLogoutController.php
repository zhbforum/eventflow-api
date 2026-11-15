<?php

declare(strict_types=1);

namespace App\Controller;

use LogicException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class OidcLogoutController
{
    #[Route(
        '/api/v1/auth/logout',
        name: 'api_auth_logout',
        methods: ['POST'],
    )]
    public function logout(): never
    {
        throw new LogicException(
            'Logout must be handled by Symfony Security',
        );
    }

    #[Route(
        '/api/v1/auth/logged-out',
        name: 'api_auth_logged_out',
        methods: ['GET'],
        format: 'json',
    )]
    public function loggedOut(): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'logged_out'],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
