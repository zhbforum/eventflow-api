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

final readonly class WebhookListController
{
    #[Route(
        '/api/v1/webhooks',
        name: 'api_webhooks_list',
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

        $active = $request->query->get('active');
        $url = $request->query->get('url');

        if ($active !== null && !in_array($active, ['true', 'false'], true)) {
            return self::badRequest('active must be true or false');
        }

        if (
            $url !== null
            && (trim($url) === '' || strlen($url) > 2048)
        ) {
            return self::badRequest('Invalid url filter');
        }

        $queryBuilder = $entityManager
            ->getRepository(WebhookEndpoint::class)
            ->createQueryBuilder('w')
            ->andWhere('w.organization = :organization')
            ->setParameter(
                'organization',
                $request->attributes->getString('eventflow.organization_id'),
            );

        if ($active !== null) {
            $queryBuilder
                ->andWhere('w.isActive = :active')
                ->setParameter('active', $active === 'true');
        }

        if ($url !== null) {
            $queryBuilder
                ->andWhere('LOCATE(:url, LOWER(w.url)) > 0')
                ->setParameter('url', strtolower(trim($url)));
        }

        $total = (int) (clone $queryBuilder)
            ->select('COUNT(w.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<WebhookEndpoint> $webhooks */
        $webhooks = $queryBuilder
            ->orderBy('w.createdAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return new JsonResponse(
            [
                'items' => array_map(
                    static fn(WebhookEndpoint $webhook): array => [
                        'id' => $webhook->getId(),
                        'url' => $webhook->getUrl(),
                        'subscribedEvents' => $webhook->getSubscribedEvents(),
                        'isActive' => $webhook->isActive(),
                        'createdAt' => $webhook->getCreatedAt()->format(DATE_ATOM),
                    ],
                    $webhooks,
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
