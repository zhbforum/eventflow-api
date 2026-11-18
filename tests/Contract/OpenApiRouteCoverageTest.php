<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

final class OpenApiRouteCoverageTest extends KernelTestCase
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    public function testEveryApplicationRouteMatchesOpenApi(): void
    {
        self::bootKernel();

        $router = static::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $specification = Yaml::parseFile(
            dirname(__DIR__, 2) . '/openapi/openapi.yaml',
        );

        self::assertIsArray($specification);
        self::assertSame('3.1.0', $specification['openapi'] ?? null);

        $paths = $specification['paths'] ?? null;
        self::assertIsArray($paths);

        $documented = [];
        $operationIds = [];

        foreach ($paths as $path => $pathItem) {
            self::assertIsString($path);
            self::assertIsArray($pathItem);

            foreach (self::METHODS as $method) {
                if (!isset($pathItem[$method])) {
                    continue;
                }

                $operation = $pathItem[$method];
                self::assertIsArray($operation);

                $operationId = $operation['operationId'] ?? null;
                self::assertIsString($operationId);
                self::assertNotSame('', $operationId);

                $operationIds[] = $operationId;
                $documented[] = strtoupper($method) . ' ' . $path;
            }
        }

        self::assertCount(
            count(array_unique($operationIds)),
            $operationIds,
            'OpenAPI operationId values must be unique.',
        );

        $actual = [];

        foreach ($router->getRouteCollection()->all() as $route) {
            $path = $route->getPath();

            if (
                !str_starts_with($path, '/api/v1/')
                && $path !== '/api/health'
                && !str_starts_with($path, '/health/')
                && $path !== '/internal/metrics'
            ) {
                continue;
            }

            foreach ($route->getMethods() as $method) {
                $normalized = strtolower($method);

                self::assertContains(
                    $normalized,
                    self::METHODS,
                    'Unexpected HTTP method on route ' . $path,
                );

                $actual[] = strtoupper($normalized) . ' ' . $path;
            }
        }

        sort($actual);
        sort($documented);

        self::assertSame(
            $actual,
            $documented,
            'OpenAPI operations must match Symfony routes exactly.',
        );
    }
}
