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
use Symfony\Component\Uid\Uuid;

final readonly class DeliveryListController
{
    #[Route(
        '/api/v1/deliveries',
        name: 'api_deliveries_list',
        methods: ['GET'],
        format: 'json',
    )]
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
            return self::badRequest('Invalid pagination parameters');
        }

        $status = $request->query->get('status');
        $eventId = $request->query->get('eventId');
        $endpointId = $request->query->get('endpointId');

        if (
            $status !== null
            && !in_array(
                $status,
                ['pending', 'processing', 'delivered', 'failed'],
                true,
            )
        ) {
            return self::badRequest('Invalid delivery status');
        }

        if ($eventId !== null && !Uuid::isValid($eventId)) {
            return self::badRequest('Invalid eventId');
        }

        if ($endpointId !== null && !Uuid::isValid($endpointId)) {
            return self::badRequest('Invalid endpointId');
        }

        $queryBuilder = $entityManager
            ->getRepository(WebhookDelivery::class)
            ->createQueryBuilder('d')
            ->innerJoin('d.event', 'e')
            ->innerJoin('d.endpoint', 'w');

        if ($status !== null) {
            $queryBuilder
                ->andWhere('d.status = :status')
                ->setParameter('status', $status);
        }

        if ($eventId !== null) {
            $queryBuilder
                ->andWhere('e.id = :eventId')
                ->setParameter('eventId', $eventId);
        }

        if ($endpointId !== null) {
            $queryBuilder
                ->andWhere('w.id = :endpointId')
                ->setParameter('endpointId', $endpointId);
        }

        $total = (int) (clone $queryBuilder)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<WebhookDelivery> $deliveries */
        $deliveries = $queryBuilder
            ->addSelect('e', 'w')
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return new JsonResponse(
            [
                'items' => array_map(
                    static fn(WebhookDelivery $delivery): array => [
                        'id' => $delivery->getId(),
                        'eventId' => $delivery->getEvent()->getId(),
                        'eventType' => $delivery->getEvent()->getType(),
                        'endpointId' => $delivery->getEndpoint()->getId(),
                        'endpointUrl' => $delivery->getEndpoint()->getUrl(),
                        'status' => $delivery->getStatus(),
                        'attempts' => $delivery->getAttempts(),
                        'createdAt' => $delivery->getCreatedAt()->format(DATE_ATOM),
                        'deliveredAt' => $delivery->getDeliveredAt()?->format(DATE_ATOM),
                        'lastError' => $delivery->getLastError(),
                    ],
                    $deliveries,
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
