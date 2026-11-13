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

final class OutboxBacklogMetricsTest extends KernelTestCase
{
    public function testStaleOutboxMetricsCountOnlyUnpublishedMessages(): void
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

        $veryOld = $this->createOutbox(
            $entityManager,
            'very-old',
        );

        $old = $this->createOutbox(
            $entityManager,
            'old',
        );

        $fresh = $this->createOutbox(
            $entityManager,
            'fresh',
        );

        $published = $this->createOutbox(
            $entityManager,
            'published',
        );

        $published->startPublishAttempt();
        $published->markPublished();

        $entityManager->flush();

        $connection = $entityManager->getConnection();

        $connection->executeStatement(
            <<<'SQL'
                UPDATE outbox_messages
                SET created_at =
                    (NOW() AT TIME ZONE 'UTC')
                    - INTERVAL '21 minutes'
                WHERE id = :id
                SQL,
            ['id' => $veryOld->getId()],
        );

        $connection->executeStatement(
            <<<'SQL'
                UPDATE outbox_messages
                SET created_at =
                    (NOW() AT TIME ZONE 'UTC')
                    - INTERVAL '7 minutes'
                WHERE id = :id
                SQL,
            ['id' => $old->getId()],
        );

        $connection->executeStatement(
            <<<'SQL'
                UPDATE outbox_messages
                SET created_at =
                    (NOW() AT TIME ZONE 'UTC')
                    - INTERVAL '1 hour'
                WHERE id = :id
                SQL,
            ['id' => $published->getId()],
        );

        $after = $collector->render();

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_outbox_messages{state="pending"}',
            ) + 3,
            self::sampleValue(
                $after,
                'eventflow_outbox_messages{state="pending"}',
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
                'eventflow_outbox_stale_messages{age="5m"}',
            ) + 2,
            self::sampleValue(
                $after,
                'eventflow_outbox_stale_messages{age="5m"}',
            ),
        );

        self::assertSame(
            self::sampleValue(
                $before,
                'eventflow_outbox_stale_messages{age="15m"}',
            ) + 1,
            self::sampleValue(
                $after,
                'eventflow_outbox_stale_messages{age="15m"}',
            ),
        );

        self::assertGreaterThanOrEqual(
            1200,
            self::sampleValue(
                $after,
                'eventflow_outbox_oldest_pending_age_seconds',
            ),
        );

        self::assertStringContainsString(
            '# TYPE eventflow_outbox_stale_messages gauge',
            $after,
        );

        self::assertStringNotContainsString(
            $veryOld->getId(),
            $after,
        );

        self::assertStringNotContainsString(
            $fresh->getDelivery()->getId(),
            $after,
        );
    }

    private function createOutbox(
        EntityManagerInterface $entityManager,
        string $label,
    ): OutboxMessage {
        $event = new Event(
            'order.created',
            'outbox-backlog-metrics-test',
            ['label' => $label],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/backlog-' . $label,
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

        return $outbox;
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
                sprintf(
                    'Metric sample "%s" was not found.',
                    $sample,
                ),
            );
        }

        return (float) $matches[1];
    }
}
