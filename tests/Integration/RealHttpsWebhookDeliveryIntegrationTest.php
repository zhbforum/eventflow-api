<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Entity\WebhookDeliveryReplay;
use App\Message\DeliverWebhookMessage;
use App\MessageHandler\DeliverWebhookHandler;
use App\Service\OutboxPublisher;
use App\Service\DeliveryReplayService;
use App\Service\WebhookSigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Messenger\Envelope;

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

    public function testRecoversFromHttp503UsingRealRabbitMqRetry(): void
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
            'https-retry-integration-test',
            ['orderId' => 'ORD-HTTPS-RETRY-001'],
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

        $deliveryId = $delivery->getId();
        $eventId = $event->getId();

        self::assertSame(
            204,
            $httpClient->request(
                'POST',
                $receiverUrl . '/__test/configure',
                [
                    'json' => [
                        'secret' => $endpoint->getSigningSecret(),
                        'failuresBeforeSuccess' => 1,
                    ],
                ],
            )->getStatusCode(),
        );

        $queueName = 'eventflow_https_retry_'
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
            $container->set(
                'messenger.transport.rabbitmq',
                $transport,
            );

            self::assertSame(
                $transport,
                $container->get('messenger.transport.rabbitmq'),
            );

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

            $bus = $container->get(
                MessageBusInterface::class,
            );

            $firstWorker = new Worker(
                ['rabbitmq' => $transport],
                $bus,
                $dispatcher,
            );

            $retryEvents = 0;

            $dispatcher->addListener(
                WorkerMessageRetriedEvent::class,
                static function () use (
                    &$retryEvents,
                    $firstWorker,
                ): void {
                    ++$retryEvents;
                    $firstWorker->stop();
                },
            );

            $firstWorker->run([
                'time_limit' => 15,
                'sleep' => 10000,
            ]);

            self::assertSame(
                1,
                $retryEvents,
                'Messenger did not schedule a retry.',
            );

            $entityManager->clear();

            $failedDelivery = $entityManager->find(
                WebhookDelivery::class,
                $deliveryId,
            );

            self::assertNotNull($failedDelivery);

            self::assertSame(
                'pending',
                $failedDelivery->getStatus(),
            );

            self::assertSame(
                1,
                $failedDelivery->getAttempts(),
            );

            self::assertStringContainsString(
                'HTTP 503',
                (string) $failedDelivery->getLastError(),
            );

            $firstState = $httpClient->request(
                'GET',
                $receiverUrl . '/__test/received',
            )->toArray();

            self::assertSame(
                1,
                $firstState['verifiedAttempts'],
            );

            self::assertSame(0, $firstState['count']);

            $retryAvailable = false;

            for ($attempt = 0; $attempt < 50; ++$attempt) {
                if ($transport->getMessageCount() === 1) {
                    $retryAvailable = true;
                    break;
                }

                usleep(200_000);
            }

            self::assertTrue(
                $retryAvailable,
                'Retry never returned to the real RabbitMQ queue.',
            );

            $secondWorker = new Worker(
                ['rabbitmq' => $transport],
                $bus,
                $dispatcher,
            );

            $handledEvents = 0;

            $dispatcher->addListener(
                WorkerMessageHandledEvent::class,
                static function () use (
                    &$handledEvents,
                    $secondWorker,
                ): void {
                    ++$handledEvents;
                    $secondWorker->stop();
                },
            );

            $secondWorker->run([
                'time_limit' => 15,
                'sleep' => 10000,
            ]);

            self::assertSame(1, $handledEvents);

            self::assertSame(
                0,
                $transport->getMessageCount(),
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
                2,
                $savedDelivery->getAttempts(),
            );

            self::assertNotNull(
                $savedDelivery->getDeliveredAt(),
            );

            self::assertNull(
                $savedDelivery->getLastError(),
            );

            $finalState = $httpClient->request(
                'GET',
                $receiverUrl . '/__test/received',
            )->toArray();

            self::assertSame(
                2,
                $finalState['verifiedAttempts'],
            );

            self::assertSame(1, $finalState['count']);

            self::assertSame(
                $deliveryId,
                $finalState['deliveries'][0]['id'],
            );

            self::assertSame(
                $eventId,
                $finalState['deliveries'][0]['payload']['id'],
            );
        } finally {
            $transport->close();

            self::deleteTemporaryQueue(
                $dsn,
                $queueName,
            );
        }
    }
    public function testManualReplayDeliversOverRealRabbitMqAndHttps(): void
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
            'manual-replay-e2e',
            ['orderId' => 'ORD-MANUAL-REPLAY'],
        );

        $endpoint = new WebhookEndpoint(
            $receiverUrl . '/hooks',
            ['order.created'],
        );

        $delivery = new WebhookDelivery(
            $event,
            $endpoint,
        );

        // Simulate a delivery that exhausted automatic retries.
        $delivery->startAttempt();
        $delivery->markFailed('Webhook returned HTTP 503');

        // Generation 0 was already published previously.
        $originalOutbox = new OutboxMessage($delivery);
        $originalOutbox->markPublished();

        foreach (
            [$event, $endpoint, $delivery, $originalOutbox] as $record
        ) {
            $entityManager->persist($record);
        }

        $entityManager->flush();

        $deliveryId = $delivery->getId();
        $eventId = $event->getId();

        self::assertSame(
            204,
            $httpClient->request(
                'POST',
                $receiverUrl . '/__test/configure',
                [
                    'json' => [
                        'secret' => $endpoint->getSigningSecret(),
                        'failuresBeforeSuccess' => 0,
                    ],
                ],
            )->getStatusCode(),
        );

        $queueName = 'eventflow_manual_replay_'
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
            $replay = (new DeliveryReplayService(
                $entityManager,
            ))->requestReplay(
                $deliveryId,
                'manual-replay-e2e',
            );

            self::assertNotNull($replay);
            self::assertSame(1, $replay->getReplayGeneration());

            $entityManager->clear();

            $savedDelivery = $entityManager->find(
                WebhookDelivery::class,
                $deliveryId,
            );

            self::assertNotNull($savedDelivery);
            self::assertSame('pending', $savedDelivery->getStatus());
            self::assertSame(1, $savedDelivery->getReplayGeneration());

            $outboxes = $entityManager
                ->getRepository(OutboxMessage::class)
                ->findBy(
                    ['delivery' => $savedDelivery],
                    ['replayGeneration' => 'ASC'],
                );

            self::assertCount(2, $outboxes);
            self::assertSame(0, $outboxes[0]->getReplayGeneration());
            self::assertSame(1, $outboxes[1]->getReplayGeneration());
            self::assertNotNull($outboxes[0]->getPublishedAt());
            self::assertNull($outboxes[1]->getPublishedAt());

            // A delayed message from generation 0 arrives first.
            $transport->send(
                new Envelope(
                    new DeliverWebhookMessage($deliveryId, 0),
                ),
            );

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

            self::assertSame(2, $transport->getMessageCount());

            $container->set(
                DeliverWebhookHandler::class,
                new DeliverWebhookHandler(
                    $entityManager,
                    $httpClient,
                    new WebhookSigner(),
                ),
            );

            $dispatcher = $container->get('event_dispatcher');

            $worker = new Worker(
                ['rabbitmq' => $transport],
                $container->get(MessageBusInterface::class),
                $dispatcher,
            );

            $handled = 0;

            $dispatcher->addListener(
                WorkerMessageHandledEvent::class,
                static function () use (
                    &$handled,
                    $worker,
                ): void {
                    ++$handled;

                    if ($handled === 2) {
                        $worker->stop();
                    }
                },
            );

            $worker->run([
                'time_limit' => 15,
                'sleep' => 10000,
            ]);

            self::assertSame(2, $handled);
            self::assertSame(0, $transport->getMessageCount());

            $received = $httpClient->request(
                'GET',
                $receiverUrl . '/__test/received',
            )->toArray();

            // Generation 0 must not perform an HTTP request.
            self::assertSame(1, $received['verifiedAttempts']);
            self::assertSame(1, $received['count']);

            self::assertSame(
                $deliveryId,
                $received['deliveries'][0]['id'],
            );

            self::assertSame(
                $eventId,
                $received['deliveries'][0]['payload']['id'],
            );

            $entityManager->clear();

            $savedDelivery = $entityManager->find(
                WebhookDelivery::class,
                $deliveryId,
            );

            self::assertNotNull($savedDelivery);
            self::assertSame('delivered', $savedDelivery->getStatus());
            self::assertSame(1, $savedDelivery->getReplayGeneration());
            self::assertSame(2, $savedDelivery->getAttempts());
            self::assertNotNull($savedDelivery->getDeliveredAt());
            self::assertNull($savedDelivery->getLastError());

            $history = $entityManager
                ->getRepository(WebhookDeliveryReplay::class)
                ->findBy(['delivery' => $savedDelivery]);

            self::assertCount(1, $history);

            self::assertSame(
                'manual-replay-e2e',
                $history[0]->getRequestedBy(),
            );

            self::assertSame(
                'Webhook returned HTTP 503',
                $history[0]->getPreviousError(),
            );

            $replayOutbox = $entityManager->find(
                OutboxMessage::class,
                $outboxes[1]->getId(),
            );

            self::assertNotNull($replayOutbox);
            self::assertNotNull($replayOutbox->getPublishedAt());
            self::assertSame(1, $replayOutbox->getPublishAttempts());
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
