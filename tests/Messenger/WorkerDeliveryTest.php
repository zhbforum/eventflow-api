<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Message\DeliverWebhookMessage;
use App\MessageHandler\DeliverWebhookHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class WorkerDeliveryTest extends KernelTestCase
{
    public function testWorkerProcessesWebhookDelivery(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $event = new Event(
            'order.created',
            'shop-service',
            ['orderId' => 'ORD-7001'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/hooks',
            ['order.created'],
        );

        $delivery = new WebhookDelivery($event, $endpoint);

        $em->persist($event);
        $em->persist($endpoint);
        $em->persist($delivery);
        $em->flush();

        $deliveryId = $delivery->getId();
        $requestCount = 0;

        $mockHttpClient = new MockHttpClient(
            static function (
                string $method,
                string $url,
            ) use (&$requestCount): MockResponse {
                ++$requestCount;

                self::assertSame('POST', $method);
                self::assertSame(
                    'https://example.com/hooks',
                    $url,
                );

                return new MockResponse('', [
                    'http_code' => 204,
                ]);
            },
        );

        $container->set(
            DeliverWebhookHandler::class,
            new DeliverWebhookHandler($em, $mockHttpClient),
        );

        /** @var InMemoryTransport $transport */
        $transport = $container->get(
            'messenger.transport.async',
        );

        $bus = $container->get(MessageBusInterface::class);
        $dispatcher = $container->get('event_dispatcher');

        $worker = new Worker(
            ['async' => $transport],
            $bus,
            $dispatcher,
        );

        $dispatcher->addListener(
            WorkerMessageHandledEvent::class,
            static function () use ($worker): void {
                $worker->stop();
            },
        );

        $bus->dispatch(
            new DeliverWebhookMessage($deliveryId),
        );

        self::assertCount(1, $transport->getSent());

        $worker->run([
            'time_limit' => 2,
            'sleep' => 10000,
        ]);

        self::assertSame(1, $requestCount);
        self::assertCount(1, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());

        $em->clear();

        $savedDelivery = $em
            ->getRepository(WebhookDelivery::class)
            ->find($deliveryId);

        self::assertNotNull($savedDelivery);
        self::assertSame(
            'delivered',
            $savedDelivery->getStatus(),
        );
        self::assertSame(1, $savedDelivery->getAttempts());
        self::assertNotNull(
            $savedDelivery->getDeliveredAt(),
        );
    }
}
