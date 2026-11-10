<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Message\DeliverWebhookMessage;
use App\Service\OutboxPublisher;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class OutboxPublisherTest extends KernelTestCase
{
    public function testPublishesPendingMessageOnlyOnce(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $outbox = $this->createOutboxMessage(
            $entityManager,
        );

        $deliveryId = $outbox->getDelivery()->getId();
        $outboxId = $outbox->getId();

        $sender = $this->createMock(SenderInterface::class);

        $sender
            ->expects(self::once())
            ->method('send')
            ->willReturnCallback(
                static function (Envelope $envelope) use (
                    $deliveryId,
                ): Envelope {
                    $message = $envelope->getMessage();

                    self::assertInstanceOf(
                        DeliverWebhookMessage::class,
                        $message,
                    );

                    self::assertSame(
                        $deliveryId,
                        $message->deliveryId,
                    );

                    return $envelope;
                },
            );

        $publisher = new OutboxPublisher(
            $entityManager,
            $sender,
        );

        $result = $publisher->publish(10);

        self::assertSame(
            [
                'processed' => 1,
                'published' => 1,
                'failed' => 0,
            ],
            $result,
        );

        $updated = $entityManager->find(
            OutboxMessage::class,
            $outboxId,
        );

        self::assertNotNull($updated);
        self::assertNotNull($updated->getPublishedAt());
        self::assertSame(1, $updated->getPublishAttempts());
        self::assertNull($updated->getLastError());

        // A published outbox entry must not be sent again.
        $secondResult = $publisher->publish(10);

        self::assertSame(
            [
                'processed' => 0,
                'published' => 0,
                'failed' => 0,
            ],
            $secondResult,
        );
    }

    public function testFailedPublishCanBeRetried(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $outbox = $this->createOutboxMessage(
            $entityManager,
        );

        $outboxId = $outbox->getId();

        $failingSender = $this->createMock(
            SenderInterface::class,
        );

        $failingSender
            ->expects(self::once())
            ->method('send')
            ->willThrowException(
                new RuntimeException(
                    'RabbitMQ unavailable',
                ),
            );

        $publisher = new OutboxPublisher(
            $entityManager,
            $failingSender,
        );

        $result = $publisher->publish(10);

        self::assertSame(
            [
                'processed' => 1,
                'published' => 0,
                'failed' => 1,
            ],
            $result,
        );

        $failedOutbox = $entityManager->find(
            OutboxMessage::class,
            $outboxId,
        );

        self::assertNotNull($failedOutbox);
        self::assertNull($failedOutbox->getPublishedAt());
        self::assertSame(
            1,
            $failedOutbox->getPublishAttempts(),
        );
        self::assertSame(
            'RabbitMQ unavailable',
            $failedOutbox->getLastError(),
        );

        // Simulate RabbitMQ recovery.
        $workingSender = $this->createMock(
            SenderInterface::class,
        );

        $workingSender
            ->expects(self::once())
            ->method('send')
            ->willReturnArgument(0);

        $retryPublisher = new OutboxPublisher(
            $entityManager,
            $workingSender,
        );

        $retryResult = $retryPublisher->publish(10);

        self::assertSame(
            [
                'processed' => 1,
                'published' => 1,
                'failed' => 0,
            ],
            $retryResult,
        );

        $recoveredOutbox = $entityManager->find(
            OutboxMessage::class,
            $outboxId,
        );

        self::assertNotNull($recoveredOutbox);
        self::assertNotNull(
            $recoveredOutbox->getPublishedAt(),
        );
        self::assertSame(
            2,
            $recoveredOutbox->getPublishAttempts(),
        );
        self::assertNull(
            $recoveredOutbox->getLastError(),
        );
    }

    private function createOutboxMessage(
        EntityManagerInterface $entityManager,
    ): OutboxMessage {
        $event = new Event(
            'order.created',
            'shop-service',
            ['orderId' => 'ORD-3001'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/webhooks',
            ['order.created'],
        );

        $delivery = new WebhookDelivery(
            $event,
            $endpoint,
        );

        $outbox = new OutboxMessage(
            $delivery,
        );

        $entityManager->persist($event);
        $entityManager->persist($endpoint);
        $entityManager->persist($delivery);
        $entityManager->persist($outbox);
        $entityManager->flush();

        return $outbox;
    }
}
