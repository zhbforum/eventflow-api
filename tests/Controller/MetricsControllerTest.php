<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\MetricsController;
use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Service\MetricsCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MetricsControllerTest extends WebTestCase
{
    private const TOKEN = 'eventflow-test-metrics-token';

    public function testMetricsRequireAuthorization(): void
    {
        $client = static::createClient();

        $client->request('GET', '/internal/metrics');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request(
            'GET',
            '/internal/metrics',
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer invalid-token',
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testMetricsAreDisabledWithoutToken(): void
    {
        self::bootKernel();

        $collector = static::getContainer()
            ->get(MetricsCollector::class);

        $controller = new MetricsController(
            $collector,
            null,
        );

        $response = $controller(
            Request::create('/internal/metrics'),
        );

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
        );
    }

    public function testMetricsReflectDatabaseState(): void
    {
        $client = static::createClient();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $collector = static::getContainer()
            ->get(MetricsCollector::class);

        $before = $collector->render();

        $event = new Event(
            'order.created',
            'metrics-test',
            ['orderId' => 'ORD-METRICS-001'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/metrics-test',
            ['order.created'],
        );

        $delivery = new WebhookDelivery(
            $event,
            $endpoint,
        );

        $outbox = new OutboxMessage($delivery);

        $entityManager->persist($event);
        $entityManager->persist($endpoint);
        $entityManager->persist($delivery);
        $entityManager->persist($outbox);
        $entityManager->flush();

        $client->request(
            'GET',
            '/internal/metrics',
            server: [
                'HTTP_AUTHORIZATION' =>
                'Bearer ' . self::TOKEN,
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $response = $client->getResponse();

        $content = (string) $response->getContent();

        self::assertStringStartsWith(
            'text/plain; version=0.0.4',
            (string) $response->headers->get('Content-Type'),
        );

        self::assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
        );

        self::assertStringContainsString(
            '# TYPE eventflow_webhook_deliveries gauge',
            $content,
        );

        self::assertStringContainsString(
            '# TYPE eventflow_outbox_messages gauge',
            $content,
        );

        self::assertStringContainsString(
            'eventflow_webhook_deliveries{status="processing"}',
            $content,
        );

        self::assertStringContainsString(
            'eventflow_webhook_deliveries{status="delivered"}',
            $content,
        );

        self::assertStringContainsString(
            'eventflow_webhook_deliveries{status="failed"}',
            $content,
        );

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_webhook_deliveries{status="pending"}',
            ) + 1,
            self::sampleValue(
                $content,
                'eventflow_webhook_deliveries{status="pending"}',
            ),
        );

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_outbox_messages{state="pending"}',
            ) + 1,
            self::sampleValue(
                $content,
                'eventflow_outbox_messages{state="pending"}',
            ),
        );

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_webhook_endpoints_active',
            ) + 1,
            self::sampleValue(
                $content,
                'eventflow_webhook_endpoints_active',
            ),
        );

        self::assertGreaterThanOrEqual(
            0,
            self::sampleValue(
                $content,
                'eventflow_outbox_oldest_pending_age_seconds',
            ),
        );

        self::assertStringNotContainsString(
            $endpoint->getUrl(),
            $content,
        );

        self::assertStringNotContainsString(
            $delivery->getId(),
            $content,
        );
    }

    private static function sampleValue(
        string $metrics,
        string $sample,
    ): float {
        $pattern = '/^'
            . preg_quote($sample, '/')
            . ' ([0-9]+(?:\.[0-9]+)?)$/m';

        if (!preg_match($pattern, $metrics, $matches)) {
            self::fail(
                sprintf('Metric sample "%s" was not found.', $sample),
            );
        }

        return (float) $matches[1];
    }
}
