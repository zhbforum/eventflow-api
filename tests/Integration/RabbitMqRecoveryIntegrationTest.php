<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Message\DeliverWebhookMessage;
use App\Service\OutboxPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;

final class RabbitMqRecoveryIntegrationTest extends KernelTestCase
{
    public function testOutboxRecoversAfterRabbitMqConnectionFailure(): void
    {
        if (getenv('RUN_RABBITMQ_INTEGRATION') !== '1') {
            self::markTestSkipped(
                'Set RUN_RABBITMQ_INTEGRATION=1 to run.',
            );
        }

        self::bootKernel();

        $dsn = $_ENV['RABBITMQ_TRANSPORT_DSN']
            ?? $_SERVER['RABBITMQ_TRANSPORT_DSN']
            ?? '';

        self::assertNotSame('', $dsn);

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $event = new Event(
            'order.created',
            'rabbitmq-recovery-test',
            ['orderId' => 'ORD-RECOVERY-001'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/recovery-test',
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

        $deliveryId = $delivery->getId();
        $outboxId = $outbox->getId();

        // Phase 1: simulate an unavailable RabbitMQ broker.
        $unavailableTransport = new AmqpTransport(
            Connection::fromDsn($dsn, [
                'host' => '127.0.0.1',
                'port' => 1,
                'connect_timeout' => 1,
                'auto_setup' => false,
            ]),
        );

        $publisher = new OutboxPublisher(
            $entityManager,
            $unavailableTransport,
        );

        $failedResult = $publisher->publish(1);

        self::assertSame(
            [
                'processed' => 1,
                'published' => 0,
                'failed' => 1,
            ],
            $failedResult,
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

        self::assertNotNull(
            $failedOutbox->getLastError(),
        );

        // Phase 2: RabbitMQ is available again.
        $queueName = 'eventflow_recovery_'
            . bin2hex(random_bytes(8));

        $workingTransport = new AmqpTransport(
            Connection::fromDsn($dsn, [
                'confirm_timeout' => 5,
                'connect_timeout' => 5,
                'exchange' => [
                    'name' => $queueName,
                ],
                'queues' => [
                    $queueName => [],
                ],
            ]),
        );

        $workingTransport->setup();

        try {
            $recoveryPublisher = new OutboxPublisher(
                $entityManager,
                $workingTransport,
            );

            $recoveryResult = $recoveryPublisher->publish(1);

            self::assertSame(
                [
                    'processed' => 1,
                    'published' => 1,
                    'failed' => 0,
                ],
                $recoveryResult,
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

            // Verify the message reached the real broker.
            self::assertSame(
                1,
                $workingTransport->getMessageCount(),
            );

            $envelopes = iterator_to_array(
                $workingTransport->get(),
                false,
            );

            self::assertCount(1, $envelopes);

            $message = $envelopes[0]->getMessage();

            self::assertInstanceOf(
                DeliverWebhookMessage::class,
                $message,
            );

            self::assertSame(
                $deliveryId,
                $message->deliveryId,
            );

            // Acknowledge without executing the HTTP handler.
            $workingTransport->ack($envelopes[0]);

            self::assertSame(
                0,
                $workingTransport->getMessageCount(),
            );

            // Already published messages are not selected again.
            self::assertSame(
                [
                    'processed' => 0,
                    'published' => 0,
                    'failed' => 0,
                ],
                $recoveryPublisher->publish(1),
            );
        } finally {
            $workingTransport->close();

            self::deleteTemporaryQueue(
                $dsn,
                $queueName,
            );
        }
    }

    private static function deleteTemporaryQueue(
        string $dsn,
        string $queueName,
    ): void {
        $parts = parse_url($dsn);

        if ($parts === false) {
            throw new \RuntimeException(
                'Invalid RabbitMQ DSN.',
            );
        }

        $pathParts = explode(
            '/',
            trim($parts['path'] ?? '', '/'),
        );

        $vhost = rawurldecode(
            $pathParts[0] ?: '%2f',
        );

        $connection = new \AMQPConnection([
            'host' => $parts['host'] ?? 'rabbitmq',
            'port' => $parts['port'] ?? 5672,
            'login' => rawurldecode(
                $parts['user'] ?? 'guest',
            ),
            'password' => rawurldecode(
                $parts['pass'] ?? 'guest',
            ),
            'vhost' => $vhost,
        ]);

        $connection->connect();

        try {
            $channel = new \AMQPChannel($connection);

            $queue = new \AMQPQueue($channel);
            $queue->setName($queueName);
            $queue->delete();

            $exchange = new \AMQPExchange($channel);
            $exchange->setName($queueName);
            $exchange->delete();
        } finally {
            $connection->disconnect();
        }
    }
}
