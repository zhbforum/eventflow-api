<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\OidcUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class OidcMeController
{
    #[Route(
        '/api/v1/auth/me',
        name: 'api_auth_me',
        methods: ['GET'],
        format: 'json',
    )]
    public function __invoke(Security $security): JsonResponse
    {
        $user = $security->getUser();

        if (!$user instanceof OidcUser) {
            return new JsonResponse(
                ['error' => 'Unauthenticated'],
                Response::HTTP_UNAUTHORIZED,
                ['Cache-Control' => 'no-store'],
            );
        }

        return new JsonResponse(
            [
                'id' => $user->getUserIdentifier(),
                'username' => $user->getUsername(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }
}
