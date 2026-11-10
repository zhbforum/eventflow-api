<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\EventListener\WebhookDeliveryFailedListener;
use App\Message\DeliverWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class WebhookDeliveryFailedListenerTest extends KernelTestCase
{
    public static function transportNames(): iterable
    {
        yield 'PostgreSQL transport' => ['async'];
        yield 'RabbitMQ transport' => ['rabbitmq'];
    }

    #[DataProvider('transportNames')]
    public function testMarksDeliveryFailedWhenRetriesExhausted(
        string $transportName,
    ): void {
        self::bootKernel();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $delivery = self::createDelivery($entityManager);
        $deliveryId = $delivery->getId();

        $event = new WorkerMessageFailedEvent(
            new Envelope(
                new DeliverWebhookMessage($deliveryId),
            ),
            $transportName,
            new RuntimeException('HTTP 503'),
        );

        $listener = new WebhookDeliveryFailedListener(
            $entityManager,
        );

        $listener($event);

        $entityManager->clear();

        $savedDelivery = $entityManager
            ->getRepository(WebhookDelivery::class)
            ->find($deliveryId);

        self::assertNotNull($savedDelivery);

        self::assertSame(
            'failed',
            $savedDelivery->getStatus(),
        );

        self::assertSame(
            'Webhook returned HTTP 503',
            $savedDelivery->getLastError(),
        );
    }

    #[DataProvider('transportNames')]
    public function testKeepsDeliveryPendingWhenRetryScheduled(
        string $transportName,
    ): void {
        self::bootKernel();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $delivery = self::createDelivery($entityManager);
        $deliveryId = $delivery->getId();

        $event = new WorkerMessageFailedEvent(
            new Envelope(
                new DeliverWebhookMessage($deliveryId),
            ),
            $transportName,
            new RuntimeException('HTTP 503'),
        );

        $event->setForRetry();

        $listener = new WebhookDeliveryFailedListener(
            $entityManager,
        );

        $listener($event);

        $entityManager->clear();

        $savedDelivery = $entityManager
            ->getRepository(WebhookDelivery::class)
            ->find($deliveryId);

        self::assertNotNull($savedDelivery);

        self::assertSame(
            'pending',
            $savedDelivery->getStatus(),
        );

        self::assertSame(
            'Webhook returned HTTP 503',
            $savedDelivery->getLastError(),
        );
    }

    private static function createDelivery(
        EntityManagerInterface $entityManager,
    ): WebhookDelivery {
        $event = new Event(
            'order.created',
            'shop-service',
            ['orderId' => 'ORD-5001'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/hooks',
            ['order.created'],
        );

        $delivery = new WebhookDelivery(
            $event,
            $endpoint,
        );

        $delivery->recordFailure(
            'Webhook returned HTTP 503',
        );

        $entityManager->persist($event);
        $entityManager->persist($endpoint);
        $entityManager->persist($delivery);
        $entityManager->flush();

        return $delivery;
    }
}
