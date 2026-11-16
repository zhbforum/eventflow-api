<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\MessageHandler\DeliverWebhookHandler;
use App\Service\OutboxPublisher;
use App\Service\WebhookSigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;

final class RealHttpsWebhookDeliveryIntegrationTest extends KernelTestCase
{
    public function testRabbitMqWorkerDeliversSignedWebhookOverHttps(): void
    {
        if (getenv('RUN_HTTPS_E2E_INTEGRATION') !== '1') {
            self::markTestSkipped(
                'Set RUN_HTTPS_E2E_INTEGRATION=1 to run.',
            );
        }

        self::bootKernel();

        $container = static::getContainer();
        $entityManager = $container->get(
            EntityManagerInterface::class,
        );

        $dsn = $_ENV['RABBITMQ_TRANSPORT_DSN']
            ?? $_SERVER['RABBITMQ_TRANSPORT_DSN']
            ?? '';

        self::assertNotSame('', $dsn);

        $httpClient = HttpClient::create([
            'cafile' => '/tls/ca.crt',
            'verify_peer' => true,
            'verify_host' => true,
            'timeout' => 5,
        ]);

        $receiverUrl = 'https://webhook-receiver:8443';

        self::assertSame(
            200,
            $httpClient->request(
                'GET',
                $receiverUrl . '/health',
            )->getStatusCode(),
        );

        $event = new Event(
            'order.created',
            'https-integration-test',
            ['orderId' => 'ORD-HTTPS-E2E-001'],
        );

        $endpoint = new WebhookEndpoint(
            $receiverUrl . '/hooks',
            ['order.created'],
        );

        $delivery = new WebhookDelivery($event, $endpoint);
        $outbox = new OutboxMessage($delivery);

        $entityManager->persist($event);
        $entityManager->persist($endpoint);
        $entityManager->persist($delivery);
        $entityManager->persist($outbox);
        $entityManager->flush();

        $eventId = $event->getId();
        $deliveryId = $delivery->getId();
        $outboxId = $outbox->getId();

        self::assertSame(
            204,
            $httpClient->request(
                'POST',
                $receiverUrl . '/__test/configure',
                [
                    'json' => [
                        'secret' => $endpoint->getSigningSecret(),
                    ],
                ],
            )->getStatusCode(),
        );

        $queueName = 'eventflow_https_test_'
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
                $transport->getMessageCount(),
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
                'time_limit' => 15,
                'sleep' => 10000,
            ]);

            self::assertSame(
                0,
                $transport->getMessageCount(),
            );

            $received = $httpClient->request(
                'GET',
                $receiverUrl . '/__test/received',
            )->toArray();

            self::assertSame(1, $received['count']);

            self::assertSame(
                $deliveryId,
                $received['deliveries'][0]['id'],
            );

            self::assertSame(
                $eventId,
                $received['deliveries'][0]['payload']['id'],
            );

            self::assertSame(
                'order.created',
                $received['deliveries'][0]['payload']['type'],
            );

            self::assertSame(
                'ORD-HTTPS-E2E-001',
                $received['deliveries'][0]['payload']['payload']['orderId'],
            );

            $entityManager->clear();

            $savedDelivery = $entityManager->find(
                WebhookDelivery::class,
                $deliveryId,
            );

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
