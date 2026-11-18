<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateWebhookRequest;
use App\Entity\Organization;
use App\Entity\WebhookEndpoint;
use App\Security\ManagementApiGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
        Request $httpRequest,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $organizationId = $httpRequest->attributes->getString(
            'eventflow.organization_id',
        );

        $organization = $entityManager->find(
            Organization::class,
            $organizationId,
        );

        if (!$organization instanceof Organization) {
            return new JsonResponse(
                ['error' => 'Forbidden'],
                Response::HTTP_FORBIDDEN,
                ['Cache-Control' => 'no-store'],
            );
        }

        $endpoint = new WebhookEndpoint(
            $request->url,
            $request->subscribedEvents,
            $organization,
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
        Request $request,
        EntityManagerInterface $entityManager,
        ManagementApiGuard $guard,
    ): JsonResponse {
        $denied = $guard->check($request);

        if ($denied !== null) {
            return $denied;
        }

        /** @var WebhookEndpoint|null $endpoint */
        $endpoint = $entityManager
            ->getRepository(WebhookEndpoint::class)
            ->createQueryBuilder('w')
            ->andWhere('w.id = :id')
            ->andWhere('w.organization = :organization')
            ->setParameter('id', $id)
            ->setParameter(
                'organization',
                $request->attributes->getString(
                    'eventflow.organization_id',
                ),
            )
            ->getQuery()
            ->getOneOrNullResult();

        if ($endpoint === null) {
            return new JsonResponse(
                ['error' => 'Webhook endpoint not found'],
                Response::HTTP_NOT_FOUND,
                ['Cache-Control' => 'no-store'],
            );
        }

        return new JsonResponse(
            self::toArray($endpoint),
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
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
