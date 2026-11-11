<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MetricsCollector;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class MetricsController
{
    public function __construct(
        private MetricsCollector $metricsCollector,

        #[Autowire('%env(default::METRICS_TOKEN)%')]
        private ?string $metricsToken,
    ) {
    }

    #[Route(
        '/internal/metrics',
        name: 'internal_metrics',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        if ($this->metricsToken === null || $this->metricsToken === '') {
            return new Response(
                '',
                Response::HTTP_NOT_FOUND,
                ['Cache-Control' => 'no-store'],
            );
        }

        $authorization = $request->headers->get(
            'Authorization',
            '',
        );

        $prefix = 'Bearer ';

        if (
            !str_starts_with($authorization, $prefix)
            || !hash_equals(
                $this->metricsToken,
                substr($authorization, strlen($prefix)),
            )
        ) {
            return new Response(
                '',
                Response::HTTP_FORBIDDEN,
                ['Cache-Control' => 'no-store'],
            );
        }

        return new Response(
            $this->metricsCollector->render(),
            Response::HTTP_OK,
            [
                'Content-Type' =>
                    'text/plain; version=0.0.4; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
