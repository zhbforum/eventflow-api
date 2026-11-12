<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Message\DeliverWebhookMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Uid\Uuid;

final class RabbitMqConsumerRecoveryIntegrationTest extends KernelTestCase
{
    public function testUnacknowledgedMessageIsRedeliveredAfterConnectionLoss(): void
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

        $queueName = 'eventflow_redelivery_'
            . bin2hex(random_bytes(8));

        $options = [
            'confirm_timeout' => 5,
            'connect_timeout' => 5,
            'exchange' => [
                'name' => $queueName,
            ],
            'queues' => [
                $queueName => [],
            ],
        ];

        $firstConsumer = new AmqpTransport(
            Connection::fromDsn($dsn, $options),
        );

        $restartedConsumer = null;

        try {
            $firstConsumer->setup();

            $deliveryId = Uuid::v7()->toRfc4122();

            $firstConsumer->send(
                new Envelope(
                    new DeliverWebhookMessage($deliveryId),
                ),
            );

            self::assertSame(
                1,
                $firstConsumer->getMessageCount(),
            );

            $received = iterator_to_array(
                $firstConsumer->get(),
                false,
            );

            self::assertCount(1, $received);

            $message = $received[0]->getMessage();

            self::assertInstanceOf(
                DeliverWebhookMessage::class,
                $message,
            );

            self::assertSame(
                $deliveryId,
                $message->deliveryId,
            );

            $firstConsumer->close();

            $restartedConsumer = new AmqpTransport(
                Connection::fromDsn($dsn, $options),
            );

            $redelivered = [];

            for ($attempt = 0; $attempt < 20; ++$attempt) {
                $redelivered = iterator_to_array(
                    $restartedConsumer->get(),
                    false,
                );

                if ($redelivered !== []) {
                    break;
                }

                usleep(100_000);
            }

            self::assertCount(
                1,
                $redelivered,
                'Unacknowledged message was not redelivered.',
            );

            $recoveredMessage = $redelivered[0]->getMessage();

            self::assertInstanceOf(
                DeliverWebhookMessage::class,
                $recoveredMessage,
            );

            self::assertSame(
                $deliveryId,
                $recoveredMessage->deliveryId,
            );

            $restartedConsumer->ack($redelivered[0]);

            self::assertSame(
                0,
                $restartedConsumer->getMessageCount(),
            );
        } finally {
            $firstConsumer->close();
            $restartedConsumer?->close();

            self::deleteTemporaryQueue($dsn, $queueName);
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
