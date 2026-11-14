<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\WebhookDelivery;
use App\Security\ManagementApiGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DeliveryShowController
{
    #[Route(
        '/api/v1/deliveries/{id}',
        name: 'api_deliveries_show',
        methods: ['GET'],
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

        $delivery = $entityManager
            ->getRepository(WebhookDelivery::class)
            ->find($id);

        if ($delivery === null) {
            return new JsonResponse(
                ['error' => 'Webhook delivery not found'],
                Response::HTTP_NOT_FOUND,
                ['Cache-Control' => 'no-store'],
            );
        }

        $event = $delivery->getEvent();
        $endpoint = $delivery->getEndpoint();

        return new JsonResponse(
            [
                'id' => $delivery->getId(),
                'status' => $delivery->getStatus(),
                'attempts' => $delivery->getAttempts(),
                'createdAt' => $delivery
                    ->getCreatedAt()
                    ->format(DATE_ATOM),
                'deliveredAt' => $delivery
                    ->getDeliveredAt()
                    ?->format(DATE_ATOM),
                'lastError' => $delivery->getLastError(),

                'event' => [
                    'id' => $event->getId(),
                    'type' => $event->getType(),
                    'source' => $event->getSource(),
                    'payload' => $event->getPayload(),
                    'createdAt' => $event
                        ->getCreatedAt()
                        ->format(DATE_ATOM),
                ],

                'endpoint' => [
                    'id' => $endpoint->getId(),
                    'url' => $endpoint->getUrl(),
                    'subscribedEvents' =>
                    $endpoint->getSubscribedEvents(),
                    'isActive' => $endpoint->isActive(),
                ],
            ],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }
}
