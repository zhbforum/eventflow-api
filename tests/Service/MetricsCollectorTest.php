<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Service\MetricsCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MetricsCollectorTest extends KernelTestCase
{
    public function testRetryAndPublishFailureMetrics(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $entityManager = $container->get(
            EntityManagerInterface::class,
        );

        $collector = $container->get(
            MetricsCollector::class,
        );

        $before = $collector->render();

        $event = new Event(
            'order.created',
            'metrics-test',
            ['orderId' => 'ORD-METRICS-RETRY'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/metrics-retry',
            ['order.created'],
        );

        $delivery = new WebhookDelivery(
            $event,
            $endpoint,
        );

        $outbox = new OutboxMessage(
            $delivery,
        );

        // Simulate a failed webhook attempt.
        $delivery->startAttempt();
        $delivery->recordFailure(
            'Webhook returned HTTP 503',
        );

        // Simulate a failed RabbitMQ publication.
        $outbox->startPublishAttempt();
        $outbox->recordFailure(
            'RabbitMQ unavailable',
        );

        $entityManager->persist($event);
        $entityManager->persist($endpoint);
        $entityManager->persist($delivery);
        $entityManager->persist($outbox);
        $entityManager->flush();

        $after = $collector->render();

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_webhook_deliveries_retrying',
            ) + 1,
            self::sampleValue(
                $after,
                'eventflow_webhook_deliveries_retrying',
            ),
        );

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_outbox_publish_failures',
            ) + 1,
            self::sampleValue(
                $after,
                'eventflow_outbox_publish_failures',
            ),
        );

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_webhook_endpoints_total',
            ) + 1,
            self::sampleValue(
                $after,
                'eventflow_webhook_endpoints_total',
            ),
        );

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_webhook_deliveries{status="pending"}',
            ) + 1,
            self::sampleValue(
                $after,
                'eventflow_webhook_deliveries{status="pending"}',
            ),
        );

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_outbox_messages{state="pending"}',
            ) + 1,
            self::sampleValue(
                $after,
                'eventflow_outbox_messages{state="pending"}',
            ),
        );

        self::assertGreaterThanOrEqual(
            0,
            self::sampleValue(
                $after,
                'eventflow_webhook_oldest_pending_age_seconds',
            ),
        );

        self::assertStringNotContainsString(
            'RabbitMQ unavailable',
            $after,
        );

        self::assertStringNotContainsString(
            $delivery->getId(),
            $after,
        );
    }

    public function testCompletedWorkIsNotCountedAsPending(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $entityManager = $container->get(
            EntityManagerInterface::class,
        );

        $collector = $container->get(
            MetricsCollector::class,
        );

        $before = $collector->render();

        $event = new Event(
            'order.created',
            'metrics-test',
            ['orderId' => 'ORD-METRICS-COMPLETE'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/metrics-complete',
            ['order.created'],
        );

        $delivery = new WebhookDelivery(
            $event,
            $endpoint,
        );

        $outbox = new OutboxMessage(
            $delivery,
        );

        $delivery->startAttempt();
        $delivery->markDelivered();

        $outbox->startPublishAttempt();
        $outbox->markPublished();

        $entityManager->persist($event);
        $entityManager->persist($endpoint);
        $entityManager->persist($delivery);
        $entityManager->persist($outbox);
        $entityManager->flush();

        $after = $collector->render();

        // Completed delivery must not count as retrying.
        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_webhook_deliveries_retrying',
            ),
            self::sampleValue(
                $after,
                'eventflow_webhook_deliveries_retrying',
            ),
        );

        // Published message must not count as failed.
        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_outbox_publish_failures',
            ),
            self::sampleValue(
                $after,
                'eventflow_outbox_publish_failures',
            ),
        );

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_webhook_deliveries{status="pending"}',
            ),
            self::sampleValue(
                $after,
                'eventflow_webhook_deliveries{status="pending"}',
            ),
        );

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_outbox_messages{state="pending"}',
            ),
            self::sampleValue(
                $after,
                'eventflow_outbox_messages{state="pending"}',
            ),
        );

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_webhook_deliveries{status="delivered"}',
            ) + 1,
            self::sampleValue(
                $after,
                'eventflow_webhook_deliveries{status="delivered"}',
            ),
        );

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_outbox_messages{state="published"}',
            ) + 1,
            self::sampleValue(
                $after,
                'eventflow_outbox_messages{state="published"}',
            ),
        );

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_webhook_endpoints_total',
            ) + 1,
            self::sampleValue(
                $after,
                'eventflow_webhook_endpoints_total',
            ),
        );
    }

    private static function sampleValue(
        string $metrics,
        string $sample,
    ): float {
        $pattern = '/^'
            . preg_quote($sample, '/')
            . ' ([0-9]+(?:\.[0-9]+)?)$/m';

        if (!preg_match(
            $pattern,
            $metrics,
            $matches,
        )) {
            self::fail(
                sprintf(
                    'Metric sample "%s" was not found.',
                    $sample,
                ),
            );
        }

        return (float) $matches[1];
    }
}
