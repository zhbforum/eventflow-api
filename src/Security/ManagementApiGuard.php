<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ManagementApiGuard
{
    public function __construct(
        #[Autowire('%env(default::MANAGEMENT_API_TOKEN)%')]
        private ?string $token,
    ) {}

    public function check(Request $request): ?JsonResponse
    {
        if ($this->token === null || $this->token === '') {
            return new JsonResponse(
                ['error' => 'Not found'],
                Response::HTTP_NOT_FOUND,
                ['Cache-Control' => 'no-store'],
            );
        }

        $authorization = $request->headers->get('Authorization', '');
        $prefix = 'Bearer ';

        if (
            !str_starts_with($authorization, $prefix)
            || !hash_equals(
                $this->token,
                substr($authorization, strlen($prefix)),
            )
        ) {
            return new JsonResponse(
                ['error' => 'Forbidden'],
                Response::HTTP_FORBIDDEN,
                ['Cache-Control' => 'no-store'],
            );
        }

        return null;
    }
}
