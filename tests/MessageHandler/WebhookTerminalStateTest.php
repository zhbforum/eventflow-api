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

final class WebhookTerminalStateTest extends KernelTestCase
{
    public function testFailedDeliveryIgnoresDuplicateMessages(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $delivery = self::createDelivery($entityManager);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $delivery->startAttempt();
            $delivery->recordFailure(
                'Webhook returned HTTP 503',
            );
        }

        $entityManager->flush();

        $message = new DeliverWebhookMessage(
            $delivery->getId(),
        );

        $failureEvent = new WorkerMessageFailedEvent(
            new Envelope($message),
            'rabbitmq',
            new RuntimeException('HTTP 503'),
        );

        $failureListener = new WebhookDeliveryFailedListener(
            $entityManager,
        );

        $failureListener($failureEvent);

        self::assertSame(
            'failed',
            $delivery->getStatus(),
        );

        self::assertSame(
            3,
            $delivery->getAttempts(),
        );

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
            $entityManager,
            $httpClient,
            new WebhookSigner(),
        );

        $handler($message);
        $handler($message);

        $entityManager->refresh($delivery);

        self::assertSame(0, $requests);

        self::assertSame(
            'failed',
            $delivery->getStatus(),
        );

        self::assertSame(
            3,
            $delivery->getAttempts(),
        );

        self::assertSame(
            'Webhook returned HTTP 503',
            $delivery->getLastError(),
        );

        self::assertNull(
            $delivery->getDeliveredAt(),
        );
    }

    public function testPendingDeliveryCanRetryAndThenIgnoreDuplicates(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $delivery = self::createDelivery($entityManager);

        $requests = 0;

        $httpClient = new MockHttpClient(
            static function () use (&$requests): MockResponse {
                ++$requests;

                return new MockResponse('', [
                    'http_code' => $requests === 1 ? 503 : 204,
                ]);
            },
        );

        $handler = new DeliverWebhookHandler(
            $entityManager,
            $httpClient,
            new WebhookSigner(),
        );

        $message = new DeliverWebhookMessage(
            $delivery->getId(),
        );

        try {
            $handler($message);

            self::fail(
                'Expected the first webhook attempt to fail.',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Webhook returned HTTP 503',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            'pending',
            $delivery->getStatus(),
        );

        self::assertSame(
            1,
            $delivery->getAttempts(),
        );

        self::assertSame(
            'Webhook returned HTTP 503',
            $delivery->getLastError(),
        );

        $handler($message);

        self::assertSame(
            'delivered',
            $delivery->getStatus(),
        );

        self::assertSame(
            2,
            $delivery->getAttempts(),
        );

        self::assertNotNull(
            $delivery->getDeliveredAt(),
        );

        self::assertNull(
            $delivery->getLastError(),
        );

        $handler($message);

        self::assertSame(2, $requests);

        self::assertSame(
            2,
            $delivery->getAttempts(),
        );
    }

    private static function createDelivery(
        EntityManagerInterface $entityManager,
    ): WebhookDelivery {
        $event = new Event(
            'order.created',
            'webhook-idempotency-test',
            ['orderId' => 'ORD-IDEMPOTENCY-001'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/idempotency',
            ['order.created'],
        );

        $delivery = new WebhookDelivery(
            $event,
            $endpoint,
        );

        $entityManager->persist($event);
        $entityManager->persist($endpoint);
        $entityManager->persist($delivery);
        $entityManager->flush();

        return $delivery;
    }
}
