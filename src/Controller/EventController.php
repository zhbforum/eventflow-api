<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateEventRequest;
use App\Entity\Event;
use App\Service\EventPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class EventController
{
    #[Route('/api/v1/events', name: 'api_events_create', methods: ['POST'], format: 'json')]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json')]
        CreateEventRequest $request,
        EventPublisher $eventPublisher,
    ): JsonResponse {
        $event = new Event(
            $request->type,
            $request->source,
            $request->payload,
        );

        $eventPublisher->publish($event);

        return new JsonResponse(
            self::toArray($event),
            Response::HTTP_CREATED,
            ['Location' => '/api/v1/events/' . $event->getId()],
        );
    }

    #[Route('/api/v1/events/{id}', name: 'api_events_show', methods: ['GET'], format: 'json')]
    public function show(
        string $id,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $event = $entityManager
            ->getRepository(Event::class)
            ->find($id);

        if ($event === null) {
            return new JsonResponse(
                ['error' => 'Event not found'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse(self::toArray($event));
    }

    private static function toArray(Event $event): array
    {
        return [
            'id' => $event->getId(),
            'type' => $event->getType(),
            'source' => $event->getSource(),
            'payload' => $event->getPayload(),
            'createdAt' => $event->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
