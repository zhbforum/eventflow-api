<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateEventRequest;
use App\Entity\Event;
use App\Entity\Organization;
use App\Exception\IdempotencyConflictException;
use App\Security\ManagementApiGuard;
use App\Security\OidcUser;
use App\Service\EventPublisher;
use App\Service\EventRequestFingerprint;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class EventController
{
    #[Route(
        '/api/v1/events',
        name: 'api_events_create',
        methods: ['POST'],
        format: 'json',
    )]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json')]
        CreateEventRequest $request,
        Request $httpRequest,
        EventPublisher $eventPublisher,
        Security $security,
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

        try {
            $payload = EventRequestFingerprint::payloadFromJson(
                $httpRequest->getContent(),
            );
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse(
                ['error' => $exception->getMessage()],
                Response::HTTP_BAD_REQUEST,
                ['Cache-Control' => 'no-store'],
            );
        }

        if (!$httpRequest->headers->has('Idempotency-Key')) {
            $event = new Event(
                $request->type,
                $request->source,
                $payload,
                $organization,
            );

            $eventPublisher->publish($event);

            return new JsonResponse(
                self::toArray($event),
                Response::HTTP_CREATED,
                [
                    'Location' => '/api/v1/events/' . $event->getId(),
                    'Cache-Control' => 'no-store',
                ],
            );
        }

        $key = $httpRequest->headers->get(
            'Idempotency-Key',
            '',
        );

        $scope = 'management-token';

        if ($httpRequest->headers->get('Authorization', '') === '') {
            $user = $security->getUser();

            if (!$user instanceof OidcUser) {
                throw new LogicException(
                    'Authenticated principal is missing.',
                );
            }

            $scope = 'oidc:' . $user->getUserIdentifier();
        }

        try {
            $result = $eventPublisher->publishIdempotent(
                $request->type,
                $request->source,
                $payload,
                $key,
                $httpRequest->getContent(),
                $scope,
                $organization,
            );
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse(
                ['error' => $exception->getMessage()],
                Response::HTTP_BAD_REQUEST,
                ['Cache-Control' => 'no-store'],
            );
        } catch (IdempotencyConflictException) {
            return new JsonResponse(
                [
                    'error' =>
                    'Idempotency-Key was already used '
                        . 'with a different request.',
                ],
                Response::HTTP_CONFLICT,
                ['Cache-Control' => 'no-store'],
            );
        }

        $event = $result->event;

        $headers = [
            'Location' => '/api/v1/events/' . $event->getId(),
            'Cache-Control' => 'no-store',
        ];

        if ($result->replayed) {
            $headers['Idempotency-Replayed'] = 'true';
        }

        return new JsonResponse(
            self::toArray($event),
            Response::HTTP_CREATED,
            $headers,
        );
    }

    #[Route(
        '/api/v1/events/{id}',
        name: 'api_events_show',
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

        /** @var Event|null $event */
        $event = $entityManager
            ->getRepository(Event::class)
            ->createQueryBuilder('e')
            ->andWhere('e.id = :id')
            ->andWhere('e.organization = :organization')
            ->setParameter('id', $id)
            ->setParameter(
                'organization',
                $request->attributes->getString(
                    'eventflow.organization_id',
                ),
            )
            ->getQuery()
            ->getOneOrNullResult();

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
            'createdAt' => $event
                ->getCreatedAt()
                ->format(DATE_ATOM),
        ];
    }
}
