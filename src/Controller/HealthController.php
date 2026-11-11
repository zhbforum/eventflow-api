<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DatabaseReadinessChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthController
{
    public function __construct(
        private DatabaseReadinessChecker $readinessChecker,
    ) {}

    #[Route(
        '/api/health',
        name: 'api_health',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'service' => 'eventflow-api',
        ]);
    }

    #[Route(
        '/health/live',
        name: 'health_live',
        methods: ['GET'],
    )]
    public function live(): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'ok'],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }

    #[Route(
        '/health/ready',
        name: 'health_ready',
        methods: ['GET'],
    )]
    public function ready(): JsonResponse
    {
        $databaseReady = $this->readinessChecker->isReady();

        return new JsonResponse(
            [
                'status' => $databaseReady
                    ? 'ready'
                    : 'not_ready',

                'checks' => [
                    'database' => $databaseReady
                        ? 'up'
                        : 'down',
                ],
            ],
            $databaseReady
                ? Response::HTTP_OK
                : Response::HTTP_SERVICE_UNAVAILABLE,
            ['Cache-Control' => 'no-store'],
        );
    }
}
