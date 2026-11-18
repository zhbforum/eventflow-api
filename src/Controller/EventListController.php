<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Security\ManagementApiGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class EventListController
{
    #[Route('/api/v1/events', name: 'api_events_list', methods: ['GET'], format: 'json')]
    public function __invoke(
        Request $request,
        EntityManagerInterface $entityManager,
        ManagementApiGuard $guard,
    ): JsonResponse {
        $denied = $guard->check($request);

        if ($denied !== null) {
            return $denied;
        }

        $page = filter_var(
            $request->query->get('page', '1'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100000]],
        );
        $limit = filter_var(
            $request->query->get('limit', '20'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100]],
        );

        if ($page === false || $limit === false) {
            return self::badRequest('page must be 1..100000 and limit must be 1..100');
        }

        $type = $request->query->get('type');
        $source = $request->query->get('source');

        if (
            ($type !== null && (trim($type) === '' || strlen($type) > 150))
            || ($source !== null && (trim($source) === '' || strlen($source) > 100))
        ) {
            return self::badRequest('Invalid type or source filter');
        }

        $queryBuilder = $entityManager
            ->getRepository(Event::class)
            ->createQueryBuilder('e')
            ->andWhere('e.organization = :organization')
            ->setParameter(
                'organization',
                $request->attributes->getString('eventflow.organization_id'),
            );

        if ($type !== null) {
            $queryBuilder
                ->andWhere('e.type = :type')
                ->setParameter('type', $type);
        }

        if ($source !== null) {
            $queryBuilder
                ->andWhere('e.source = :source')
                ->setParameter('source', $source);
        }

        $total = (int) (clone $queryBuilder)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<Event> $events */
        $events = $queryBuilder
            ->orderBy('e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return new JsonResponse(
            [
                'items' => array_map(
                    static fn (Event $event): array => [
                        'id' => $event->getId(),
                        'type' => $event->getType(),
                        'source' => $event->getSource(),
                        'payload' => $event->getPayload(),
                        'createdAt' => $event->getCreatedAt()->format(DATE_ATOM),
                    ],
                    $events,
                ),
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int) ceil($total / $limit),
                ],
            ],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }

    private static function badRequest(string $message): JsonResponse
    {
        return new JsonResponse(
            ['error' => $message],
            Response::HTTP_BAD_REQUEST,
            ['Cache-Control' => 'no-store'],
        );
    }
}
