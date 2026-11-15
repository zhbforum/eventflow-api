<?php

declare(strict_types=1);

namespace App\Controller;

use LogicException;
use Symfony\Component\Routing\Attribute\Route;

final class OidcCallbackController
{
    #[Route(
        '/api/v1/auth/callback',
        name: 'api_auth_callback',
        methods: ['GET'],
    )]
    public function __invoke(): never
    {
        throw new LogicException(
            'OIDC callback must be handled by the security firewall',
        );
    }
}
