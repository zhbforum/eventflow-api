<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\EventListener\WebhookDeliveryFailedListener;
use App\Message\DeliverWebhookMessage;
use App\MessageHandler\DeliverWebhookHandler;
use App\Service\WebhookSigner;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class WebhookReplayGenerationTest extends KernelTestCase
{
    public function testStaleMessageCannotDeliverWebhook(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(
            EntityManagerInterface::class,
        );

        $delivery = self::createFailedDelivery($em);
        $deliveryId = $delivery->getId();

        $delivery->requestReplay();
        $em->flush();

        $requests = 0;

        $httpClient = new MockHttpClient(
            static function () use (&$requests): MockResponse {
                ++$requests;

                return new MockResponse('', [
                    'http_code' => 204,
                ]);
            },
        );

        $handler = new DeliverWebhookHandler(
            $em,
            $httpClient,
            new WebhookSigner(),
        );

        $handler(new DeliverWebhookMessage($deliveryId, 0));

        $em->refresh($delivery);

        self::assertSame(0, $requests);
        self::assertSame('pending', $delivery->getStatus());
        self::assertSame(1, $delivery->getAttempts());
        self::assertSame(1, $delivery->getReplayGeneration());

        $handler(new DeliverWebhookMessage($deliveryId, 1));

        $em->refresh($delivery);

        self::assertSame(1, $requests);
        self::assertSame('delivered', $delivery->getStatus());
        self::assertSame(2, $delivery->getAttempts());
        self::assertNotNull($delivery->getDeliveredAt());

        $handler(new DeliverWebhookMessage($deliveryId, 0));
        $handler(new DeliverWebhookMessage($deliveryId, 1));

        self::assertSame(1, $requests);
    }

    public function testStaleFailureCannotMarkReplayAsFailed(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(
            EntityManagerInterface::class,
        );

        $delivery = self::createFailedDelivery($em);
        $deliveryId = $delivery->getId();

        $delivery->requestReplay();
        $em->flush();

        $listener = new WebhookDeliveryFailedListener($em);

        $staleEvent = new WorkerMessageFailedEvent(
            new Envelope(
                new DeliverWebhookMessage($deliveryId, 0),
            ),
            'rabbitmq',
            new RuntimeException('Old HTTP 503 failure'),
        );

        $listener($staleEvent);

        $em->refresh($delivery);

        self::assertSame('pending', $delivery->getStatus());
        self::assertSame(1, $delivery->getReplayGeneration());
        self::assertSame(1, $delivery->getAttempts());
        self::assertNull($delivery->getLastError());

        $currentEvent = new WorkerMessageFailedEvent(
            new Envelope(
                new DeliverWebhookMessage($deliveryId, 1),
            ),
            'rabbitmq',
            new RuntimeException('New HTTP 503 failure'),
        );

        $listener($currentEvent);

        $em->refresh($delivery);

        self::assertSame('failed', $delivery->getStatus());
        self::assertSame(1, $delivery->getReplayGeneration());

        self::assertSame(
            'Webhook delivery failed: New HTTP 503 failure',
            $delivery->getLastError(),
        );
    }

    private static function createFailedDelivery(
        EntityManagerInterface $em,
    ): WebhookDelivery {
        $event = new Event(
            'order.created',
            'replay-generation-test',
            ['orderId' => 'ORD-GEN-001'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/hooks',
            ['order.created'],
        );

        $delivery = new WebhookDelivery($event, $endpoint);

        $delivery->startAttempt();
        $delivery->markFailed('Original HTTP 503 failure');

        $em->persist($event);
        $em->persist($endpoint);
        $em->persist($delivery);
        $em->flush();

        return $delivery;
    }
}
