<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Message\DeliverWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class MessengerFailureTransportTest extends KernelTestCase
{
    public static function transportNames(): iterable
    {
        yield 'PostgreSQL transport' => ['async'];
        yield 'RabbitMQ transport' => ['rabbitmq'];
    }

    #[DataProvider('transportNames')]
    public function testMessageIsRetriedWhenAttemptsRemain(
        string $transportName,
    ): void {
        self::bootKernel();

        $container = self::getContainer();

        $entityManager = $container->get(
            EntityManagerInterface::class,
        );

        $delivery = self::createDelivery($entityManager);

        $envelope = new Envelope(
            new DeliverWebhookMessage($delivery->getId()),
            [new RedeliveryStamp(2)],
        );

        $event = new WorkerMessageFailedEvent(
            $envelope,
            $transportName,
            new RuntimeException(
                'Webhook returned HTTP 503',
            ),
        );

        $container->get('event_dispatcher')
            ->dispatch($event);

        /** @var InMemoryTransport $transport */
        $transport = $container->get(
            'messenger.transport.' . $transportName,
        );

        /** @var InMemoryTransport $failed */
        $failed = $container->get(
            'messenger.transport.failed',
        );

        self::assertTrue($event->willRetry());

        self::assertCount(
            1,
            $transport->getSent(),
        );

        self::assertCount(
            0,
            $failed->getSent(),
        );

        self::assertSame(
            $delivery->getId(),
            $transport->getSent()[0]
                ->getMessage()
                ->deliveryId,
        );

        self::assertSame(
            'pending',
            $delivery->getStatus(),
        );
    }

    #[DataProvider('transportNames')]
    public function testMessageMovesToFailedAfterRetries(
        string $transportName,
    ): void {
        self::bootKernel();

        $container = self::getContainer();

        $entityManager = $container->get(
            EntityManagerInterface::class,
        );

        $delivery = self::createDelivery($entityManager);
        $deliveryId = $delivery->getId();

        $envelope = new Envelope(
            new DeliverWebhookMessage($deliveryId),
            [new RedeliveryStamp(3)],
        );

        $event = new WorkerMessageFailedEvent(
            $envelope,
            $transportName,
            new RuntimeException(
                'Webhook returned HTTP 503',
            ),
        );

        $container->get('event_dispatcher')
            ->dispatch($event);

        /** @var InMemoryTransport $transport */
        $transport = $container->get(
            'messenger.transport.' . $transportName,
        );

        /** @var InMemoryTransport $failed */
        $failed = $container->get(
            'messenger.transport.failed',
        );

        self::assertFalse($event->willRetry());

        self::assertCount(
            0,
            $transport->getSent(),
        );

        self::assertCount(
            1,
            $failed->getSent(),
        );

        self::assertSame(
            $deliveryId,
            $failed->getSent()[0]
                ->getMessage()
                ->deliveryId,
        );

        // Verify the delivery status was persisted.
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

    private static function createDelivery(
        EntityManagerInterface $entityManager,
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
