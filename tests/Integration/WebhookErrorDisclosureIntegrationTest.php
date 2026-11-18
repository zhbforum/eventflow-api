<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\EventListener\WebhookDeliveryFailedListener;
use App\Message\DeliverWebhookMessage;
use App\MessageHandler\DeliverWebhookHandler;
use App\Service\WebhookSigner;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class WebhookErrorDisclosureIntegrationTest extends WebTestCase
{
    public function testInternalExceptionDoesNotLeakThroughDeliveryApi(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $delivery = self::createDelivery($em);
        $secret = 'sensitive-token-' . bin2hex(random_bytes(8));

        $httpClient = new MockHttpClient(
            static function () use ($secret): MockResponse {
                throw new RuntimeException(
                    'Authorization: Bearer ' . $secret,
                );
            },
        );

        $handler = new DeliverWebhookHandler(
            $em,
            $httpClient,
            new WebhookSigner(),
        );

        try {
            $handler(
                new DeliverWebhookMessage($delivery->getId()),
            );

            self::fail('Expected webhook delivery failure.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Webhook delivery failed',
                $exception->getMessage(),
            );

            self::assertNull($exception->getPrevious());
        }

        $em->refresh($delivery);

        self::assertSame(
            'Webhook delivery failed',
            $delivery->getLastError(),
        );

        $client->request(
            'GET',
            '/api/v1/deliveries/' . $delivery->getId(),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' =>
                'Bearer eventflow-test-management-token',
            ],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString(
            $secret,
            $body,
        );

        $data = json_decode(
            $body,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'Webhook delivery failed',
            $data['lastError'],
        );
    }

    public function testTerminalFailureDoesNotPersistExceptionMessage(): void
    {
        static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $delivery = self::createDelivery($em);

        $delivery->startAttempt();
        $em->flush();

        $secret = 'private-secret-' . bin2hex(random_bytes(8));

        $event = new WorkerMessageFailedEvent(
            new Envelope(
                new DeliverWebhookMessage($delivery->getId()),
            ),
            'rabbitmq',
            new RuntimeException(
                'Failed with secret: ' . $secret,
            ),
        );

        $listener = new WebhookDeliveryFailedListener($em);

        $listener($event);

        $em->refresh($delivery);

        self::assertSame('failed', $delivery->getStatus());

        self::assertSame(
            'Webhook delivery failed',
            $delivery->getLastError(),
        );

        self::assertStringNotContainsString(
            $secret,
            (string) $delivery->getLastError(),
        );
    }

    private static function createDelivery(
        EntityManagerInterface $em,
    ): WebhookDelivery {
        $event = new Event(
            'order.created',
            'security-audit',
            ['orderId' => 'SECURITY-001'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/security-hook',
            ['order.created'],
        );

        $delivery = new WebhookDelivery($event, $endpoint);

        $em->persist($event);
        $em->persist($endpoint);
        $em->persist($delivery);
        $em->flush();

        return $delivery;
    }
}
