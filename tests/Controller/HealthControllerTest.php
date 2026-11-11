<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\HealthController;
use App\Service\DatabaseReadinessChecker;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HealthControllerTest extends WebTestCase
{
    public function testExistingHealthEndpointRemainsAvailable(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/health');

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        self::assertSame(
            [
                'status' => 'ok',
                'service' => 'eventflow-api',
            ],
            json_decode(
                (string) $client->getResponse()->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testLivenessEndpoint(): void
    {
        $client = static::createClient();

        $client->request('GET', '/health/live');

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $response = $client->getResponse();

        self::assertSame(
            ['status' => 'ok'],
            json_decode(
                (string) $response->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
    }

    public function testReadinessEndpointWhenDatabaseIsAvailable(): void
    {
        $client = static::createClient();

        $client->request('GET', '/health/ready');

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $response = $client->getResponse();

        self::assertSame(
            [
                'status' => 'ready',
                'checks' => [
                    'database' => 'up',
                ],
            ],
            json_decode(
                (string) $response->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );
    }

    public function testReadinessFailsWhenDatabaseIsUnavailable(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT 1')
            ->willThrowException(
                new RuntimeException(
                    'Database connection failed: secret-password',
                ),
            );

        $controller = new HealthController(
            new DatabaseReadinessChecker($connection),
        );

        $response = $controller->ready();

        self::assertSame(
            Response::HTTP_SERVICE_UNAVAILABLE,
            $response->getStatusCode(),
        );

        self::assertSame(
            [
                'status' => 'not_ready',
                'checks' => [
                    'database' => 'down',
                ],
            ],
            json_decode(
                (string) $response->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertStringNotContainsString(
            'secret-password',
            (string) $response->getContent(),
        );
    }

    public function testLivenessDoesNotQueryDatabase(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects(self::never())
            ->method('fetchOne');

        $controller = new HealthController(
            new DatabaseReadinessChecker($connection),
        );

        $response = $controller->live();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testReadinessFailsOnUnexpectedDatabaseResponse(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT 1')
            ->willReturn(false);

        $controller = new HealthController(
            new DatabaseReadinessChecker($connection),
        );

        $response = $controller->ready();

        self::assertSame(
            Response::HTTP_SERVICE_UNAVAILABLE,
            $response->getStatusCode(),
        );
    }
}
