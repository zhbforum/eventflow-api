<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Message\DeliverWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class MessengerFailureTransportTest extends KernelTestCase
{
    public function testMessageIsRetriedWhenAttemptsRemain(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $delivery = self::createDelivery($em);

        $envelope = new Envelope(
            new DeliverWebhookMessage($delivery->getId()),
            [new RedeliveryStamp(2)],
        );

        $event = new WorkerMessageFailedEvent(
            $envelope,
            'async',
            new RuntimeException('Webhook returned HTTP 503'),
        );

        $container->get('event_dispatcher')->dispatch($event);

        /** @var InMemoryTransport $async */
        $async = $container->get('messenger.transport.async');

        /** @var InMemoryTransport $failed */
        $failed = $container->get('messenger.transport.failed');

        self::assertTrue($event->willRetry());
        self::assertCount(1, $async->getSent());
        self::assertCount(0, $failed->getSent());

        self::assertSame(
            $delivery->getId(),
            $async->getSent()[0]->getMessage()->deliveryId,
        );

        self::assertSame('pending', $delivery->getStatus());
    }

    public function testMessageMovesToFailedAfterRetries(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $delivery = self::createDelivery($em);
        $deliveryId = $delivery->getId();

        $envelope = new Envelope(
            new DeliverWebhookMessage($deliveryId),
            [new RedeliveryStamp(3)],
        );

        $event = new WorkerMessageFailedEvent(
            $envelope,
            'async',
            new RuntimeException('Webhook returned HTTP 503'),
        );

        $container->get('event_dispatcher')->dispatch($event);

        /** @var InMemoryTransport $async */
        $async = $container->get('messenger.transport.async');

        /** @var InMemoryTransport $failed */
        $failed = $container->get('messenger.transport.failed');

        self::assertFalse($event->willRetry());
        self::assertCount(0, $async->getSent());
        self::assertCount(1, $failed->getSent());

        self::assertSame(
            $deliveryId,
            $failed->getSent()[0]->getMessage()->deliveryId,
        );

        // Verify that the failed status was persisted.
        $em->clear();

        $savedDelivery = $em
            ->getRepository(WebhookDelivery::class)
            ->find($deliveryId);

        self::assertNotNull($savedDelivery);
        self::assertSame('failed', $savedDelivery->getStatus());
        self::assertSame(
            'Webhook returned HTTP 503',
            $savedDelivery->getLastError(),
        );
    }

    private static function createDelivery(
        EntityManagerInterface $em,
    ): WebhookDelivery {
        $event = new Event(
            'order.created',
            'shop-service',
            ['orderId' => 'ORD-6001'],
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
