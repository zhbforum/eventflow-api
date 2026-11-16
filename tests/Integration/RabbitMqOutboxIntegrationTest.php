<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\MessageHandler\DeliverWebhookHandler;
use App\Service\WebhookSigner;
use App\Service\OutboxPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

final class RabbitMqOutboxIntegrationTest extends KernelTestCase
{
    public function testOutboxPublishesAndWorkerDelivers(): void
    {
        if (getenv('RUN_RABBITMQ_INTEGRATION') !== '1') {
            self::markTestSkipped(
                'Set RUN_RABBITMQ_INTEGRATION=1 to run.',
            );
        }

        self::bootKernel();

        $container = static::getContainer();

        $dsn = $_ENV['RABBITMQ_TRANSPORT_DSN']
            ?? $_SERVER['RABBITMQ_TRANSPORT_DSN']
            ?? '';

        self::assertNotSame('', $dsn);

        $queueName = 'eventflow_test_'
            . bin2hex(random_bytes(8));

        $transport = new AmqpTransport(
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

        $transport->setup();

        try {
            $entityManager = $container->get(
                EntityManagerInterface::class,
            );

            $event = new Event(
                'order.created',
                'integration-test',
                ['orderId' => 'ORD-RABBITMQ-001'],
            );

            $endpoint = new WebhookEndpoint(
                'https://example.com/integration-test',
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

            $publisher = new OutboxPublisher(
                $entityManager,
                $transport,
            );

            self::assertSame(
                [
                    'processed' => 1,
                    'published' => 1,
                    'failed' => 0,
                ],
                $publisher->publish(1),
            );

            $savedOutbox = $entityManager->find(
                OutboxMessage::class,
                $outboxId,
            );

            self::assertNotNull($savedOutbox);
            self::assertNotNull(
                $savedOutbox->getPublishedAt(),
            );

            self::assertSame(
                1,
                $savedOutbox->getPublishAttempts(),
            );

            self::assertSame(
                1,
                $transport->getMessageCount(),
            );

            $requestCount = 0;

            $httpClient = new MockHttpClient(
                static function (
                    string $method,
                    string $url,
                ) use (&$requestCount): MockResponse {
                    ++$requestCount;

                    self::assertSame('POST', $method);

                    self::assertSame(
                        'https://example.com/integration-test',
                        $url,
                    );

                    return new MockResponse('', [
                        'http_code' => 204,
                    ]);
                },
            );

            $container->set(
                DeliverWebhookHandler::class,
                new DeliverWebhookHandler(
                    $entityManager,
                    $httpClient,
                    new WebhookSigner(),
                ),
            );

            $dispatcher = $container->get(
                'event_dispatcher',
            );

            $worker = new Worker(
                ['rabbitmq' => $transport],
                $container->get(MessageBusInterface::class),
                $dispatcher,
            );

            $dispatcher->addListener(
                WorkerMessageHandledEvent::class,
                static function () use ($worker): void {
                    $worker->stop();
                },
            );

            $worker->run([
                'time_limit' => 5,
                'sleep' => 10000,
            ]);

            self::assertSame(1, $requestCount);

            self::assertSame(
                0,
                $transport->getMessageCount(),
            );

            $entityManager->clear();

            $savedDelivery = $entityManager
                ->getRepository(WebhookDelivery::class)
                ->find($deliveryId);

            self::assertNotNull($savedDelivery);

            self::assertSame(
                'delivered',
                $savedDelivery->getStatus(),
            );

            self::assertSame(
                1,
                $savedDelivery->getAttempts(),
            );

            self::assertNotNull(
                $savedDelivery->getDeliveredAt(),
            );
        } finally {
            $transport->close();

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
