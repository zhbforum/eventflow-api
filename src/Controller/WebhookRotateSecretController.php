<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\WebhookEndpoint;
use App\Security\ManagementApiGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class WebhookRotateSecretController
{
    #[Route(
        '/api/v1/webhooks/{id}/rotate-secret',
        name: 'api_webhooks_rotate_secret',
        methods: ['POST'],
        format: 'json',
    )]
    public function __invoke(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager,
        ManagementApiGuard $guard,
    ): JsonResponse {
        $denied = $guard->check($request);

        if ($denied !== null) {
            return $denied;
        }

        $endpoint = $entityManager
            ->getRepository(WebhookEndpoint::class)
            ->find($id);

        if ($endpoint === null) {
            return new JsonResponse(
                ['error' => 'Webhook endpoint not found'],
                Response::HTTP_NOT_FOUND,
                ['Cache-Control' => 'no-store'],
            );
        }

        $secret = $endpoint->rotateSigningSecret();

        $entityManager->flush();

        return new JsonResponse(
            [
                'id' => $endpoint->getId(),
                'signingSecret' => $secret,
            ],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }
}
