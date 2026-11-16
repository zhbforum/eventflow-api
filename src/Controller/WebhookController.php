<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateWebhookRequest;
use App\Entity\WebhookEndpoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class WebhookController
{
    #[Route(
        '/api/v1/webhooks',
        name: 'api_webhooks_create',
        methods: ['POST'],
        format: 'json',
    )]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json')]
        CreateWebhookRequest $request,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $endpoint = new WebhookEndpoint(
            $request->url,
            $request->subscribedEvents,
        );

        $entityManager->persist($endpoint);
        $entityManager->flush();


        return new JsonResponse(
            [
                ...self::toArray($endpoint),
                'signingSecret' => $endpoint->getSigningSecret(),
            ],
            Response::HTTP_CREATED,
            [
                'Location' => '/api/v1/webhooks/' . $endpoint->getId(),
                'Cache-Control' => 'no-store',
            ],
        );
    }

    #[Route(
        '/api/v1/webhooks/{id}',
        name: 'api_webhooks_show',
        methods: ['GET'],
        format: 'json',
    )]
    public function show(
        string $id,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $endpoint = $entityManager
            ->getRepository(WebhookEndpoint::class)
            ->find($id);

        if ($endpoint === null) {
            return new JsonResponse(
                ['error' => 'Webhook endpoint not found'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(self::toArray($endpoint));
    }

    private static function toArray(
        WebhookEndpoint $endpoint,
    ): array {
        return [
            'id' => $endpoint->getId(),
            'url' => $endpoint->getUrl(),
            'subscribedEvents' => $endpoint->getSubscribedEvents(),
            'isActive' => $endpoint->isActive(),
            'createdAt' => $endpoint->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
