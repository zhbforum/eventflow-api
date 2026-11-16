<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Message\DeliverWebhookMessage;
use App\MessageHandler\DeliverWebhookHandler;
use App\Service\WebhookSigner;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebhookSignatureRotationTest extends KernelTestCase
{
    public function testRetryUsesRotatedSecret(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(
            EntityManagerInterface::class,
        );

        $event = new Event(
            'order.created',
            'shop-service',
            ['orderId' => 'ORD-1'],
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

        $oldSecret = $endpoint->getSigningSecret();
        $requests = [];

        $httpClient = new MockHttpClient(
            static function (
                string $method,
                string $url,
                array $options,
            ) use (&$requests): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame(
                    'https://example.com/hooks',
                    $url,
                );

                $requests[] = $options;

                return new MockResponse('', [
                    'http_code' =>
                    count($requests) === 1 ? 503 : 204,
                ]);
            },
        );

        $handler = new DeliverWebhookHandler(
            $em,
            $httpClient,
            new WebhookSigner(),
        );

        $message = new DeliverWebhookMessage(
            $delivery->getId(),
        );

        try {
            $handler($message);

            self::fail(
                'Expected first delivery attempt to fail',
            );
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Webhook returned HTTP 503',
                $exception->getMessage(),
            );
        }

        $newSecret = $endpoint->rotateSigningSecret();
        $em->flush();

        $handler($message);

        self::assertCount(2, $requests);
        self::assertNotSame($oldSecret, $newSecret);
        self::assertSame('delivered', $delivery->getStatus());
        self::assertSame(2, $delivery->getAttempts());

        foreach ([$oldSecret, $newSecret] as $index => $secret) {
            $body = $requests[$index]['body'];

            self::assertIsString($body);

            $headers = $requests[$index]['headers'];

            self::assertIsArray($headers);

            $deliveryId = self::header(
                $headers,
                'X-EventFlow-Delivery-Id',
            );

            $timestamp = self::header(
                $headers,
                'X-EventFlow-Timestamp',
            );

            $signature = self::header(
                $headers,
                'X-EventFlow-Signature',
            );

            self::assertSame(
                $delivery->getId(),
                $deliveryId,
            );

            self::assertMatchesRegularExpression(
                '/^[1-9][0-9]*$/D',
                $timestamp,
            );

            self::assertSame(
                'v1=' . hash_hmac(
                    'sha256',
                    sprintf(
                        'v1.%s.%s.%s',
                        $timestamp,
                        $deliveryId,
                        $body,
                    ),
                    $secret,
                ),
                $signature,
            );
        }

        $retryBody = $requests[1]['body'];
        $retryHeaders = $requests[1]['headers'];

        $retryTimestamp = self::header(
            $retryHeaders,
            'X-EventFlow-Timestamp',
        );

        $retrySignature = self::header(
            $retryHeaders,
            'X-EventFlow-Signature',
        );

        self::assertNotSame(
            'v1=' . hash_hmac(
                'sha256',
                sprintf(
                    'v1.%s.%s.%s',
                    $retryTimestamp,
                    $delivery->getId(),
                    $retryBody,
                ),
                $oldSecret,
            ),
            $retrySignature,
        );
    }

    /**
     * @param array<string|int, string> $headers
     */
    private static function header(
        array $headers,
        string $name,
    ): string {
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);

            if (
                count($parts) === 2
                && strcasecmp(trim($parts[0]), $name) === 0
            ) {
                return trim($parts[1]);
            }
        }

        self::fail(sprintf('Missing header: %s', $name));
    }
}
