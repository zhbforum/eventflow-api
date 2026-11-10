<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\EventListener\WebhookDeliveryFailedListener;
use App\Message\DeliverWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class WebhookDeliveryFailedListenerTest extends KernelTestCase
{
    public function testMarksDeliveryFailedWhenRetriesExhausted(): void
    {
        self::bootKernel();

        $em = static::getContainer()
            ->get(EntityManagerInterface::class);

        $delivery = self::createDelivery($em);

        $event = new WorkerMessageFailedEvent(
            new Envelope(
                new DeliverWebhookMessage($delivery->getId()),
            ),
            'async',
            new RuntimeException('HTTP 503'),
        );

        $listener = new WebhookDeliveryFailedListener($em);
        $listener($event);

        self::assertSame('failed', $delivery->getStatus());
        self::assertSame(
            'Webhook returned HTTP 503',
            $delivery->getLastError(),
        );
    }

    public function testKeepsDeliveryPendingWhenRetryScheduled(): void
    {
        self::bootKernel();

        $em = static::getContainer()
            ->get(EntityManagerInterface::class);

        $delivery = self::createDelivery($em);

        $event = new WorkerMessageFailedEvent(
            new Envelope(
                new DeliverWebhookMessage($delivery->getId()),
            ),
            'async',
            new RuntimeException('HTTP 503'),
        );

        $event->setForRetry();

        $listener = new WebhookDeliveryFailedListener($em);
        $listener($event);

        self::assertSame('pending', $delivery->getStatus());
        self::assertSame(
            'Webhook returned HTTP 503',
            $delivery->getLastError(),
        );
    }

    private static function createDelivery(
        EntityManagerInterface $em,
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

        $delivery = new WebhookDelivery($event, $endpoint);
        $delivery->recordFailure('Webhook returned HTTP 503');

        $em->persist($event);
        $em->persist($endpoint);
        $em->persist($delivery);
        $em->flush();

        return $delivery;
    }
}
