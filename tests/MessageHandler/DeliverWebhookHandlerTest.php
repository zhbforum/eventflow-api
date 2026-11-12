<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Message\DeliverWebhookMessage;
use App\MessageHandler\DeliverWebhookHandler;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DeliverWebhookHandlerTest extends KernelTestCase
{
    public function testSuccessfulDeliveryIsNotSentTwice(): void
    {
        self::bootKernel();

        $em = self::getContainer()
            ->get(EntityManagerInterface::class);

        $delivery = self::createDelivery($em);
        $requests = 0;

        $httpClient = new MockHttpClient(
            function (string $method, string $url) use (&$requests): MockResponse {
                ++$requests;

                self::assertSame('POST', $method);
                self::assertSame('https://example.com/hooks', $url);

                return new MockResponse('', ['http_code' => 204]);
            },
        );

        $handler = new DeliverWebhookHandler($em, $httpClient);
        $message = new DeliverWebhookMessage($delivery->getId());

        $handler($message);
        $handler($message);

        self::assertSame(1, $requests);
        self::assertSame('delivered', $delivery->getStatus());
        self::assertSame(1, $delivery->getAttempts());
        self::assertNotNull($delivery->getDeliveredAt());
        self::assertNull($delivery->getLastError());
    }

    public function testFailedDeliveryRemainsRetryable(): void
    {
        self::bootKernel();

        $em = self::getContainer()
            ->get(EntityManagerInterface::class);

        $delivery = self::createDelivery($em);

        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
        ]);

        $handler = new DeliverWebhookHandler($em, $httpClient);

        try {
            $handler(new DeliverWebhookMessage($delivery->getId()));
            self::fail('Expected HTTP delivery failure');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Webhook returned HTTP 503',
                $exception->getMessage(),
            );
        }

        self::assertSame('pending', $delivery->getStatus());
        self::assertSame(1, $delivery->getAttempts());
        self::assertSame(
            'Webhook returned HTTP 503',
            $delivery->getLastError(),
        );
        self::assertNull($delivery->getDeliveredAt());
    }

    private static function createDelivery(
        EntityManagerInterface $em,
    ): WebhookDelivery {
        $event = new Event(
            'order.created',
            'shop-service',
            ['orderId' => 'ORD-4001'],
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

        return $delivery;
    }


    public function testRedirectIsNotFollowed(): void
    {
        self::bootKernel();

        $em = self::getContainer()
            ->get(EntityManagerInterface::class);

        $delivery = self::createDelivery($em);
        $requests = 0;

        $httpClient = new MockHttpClient(
            function (
                string $method,
                string $url,
                array $options,
            ) use (&$requests): MockResponse {
                ++$requests;

                self::assertSame('POST', $method);
                self::assertSame(
                    'https://example.com/hooks',
                    $url,
                );
                self::assertSame(0, $options['max_redirects']);

                return new MockResponse('', [
                    'http_code' => 302,
                    'response_headers' => [
                        'Location: https://127.0.0.1/private',
                    ],
                ]);
            },
        );

        $handler = new DeliverWebhookHandler($em, $httpClient);

        try {
            $handler(new DeliverWebhookMessage($delivery->getId()));

            self::fail('Expected redirect rejection');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Webhook returned HTTP 302',
                $exception->getMessage(),
            );
        }

        self::assertSame(1, $requests);
        self::assertSame('pending', $delivery->getStatus());
        self::assertSame(1, $delivery->getAttempts());
    }
}
