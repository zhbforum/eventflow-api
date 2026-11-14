<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class DeliveryShowControllerTest extends WebTestCase
{
    private const AUTHORIZATION = [
        'HTTP_AUTHORIZATION' =>
        'Bearer eventflow-test-management-token',
    ];

    public function testShowRequiresManagementToken(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/api/v1/deliveries/00000000-0000-7000-8000-000000000000',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request(
            'GET',
            '/api/v1/deliveries/00000000-0000-7000-8000-000000000000',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer wrong-token'],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testShowFailedDelivery(): void
    {
        $client = static::createClient();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $event = new Event(
            'order.created',
            'shop-service',
            ['orderId' => 'ORD-500'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/webhook',
            ['order.created'],
        );

        $delivery = new WebhookDelivery($event, $endpoint);
        $delivery->startAttempt();
        $delivery->markFailed('Webhook returned HTTP 503');

        $entityManager->persist($event);
        $entityManager->persist($endpoint);
        $entityManager->persist($delivery);
        $entityManager->flush();

        $client->request(
            'GET',
            '/api/v1/deliveries/' . $delivery->getId(),
            [],
            [],
            self::AUTHORIZATION,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = self::decodeResponse(
            $client->getResponse()->getContent(),
        );

        self::assertSame($delivery->getId(), $data['id']);
        self::assertSame('failed', $data['status']);
        self::assertSame(1, $data['attempts']);
        self::assertNull($data['deliveredAt']);
        self::assertSame(
            'Webhook returned HTTP 503',
            $data['lastError'],
        );

        self::assertSame($event->getId(), $data['event']['id']);
        self::assertSame(
            'order.created',
            $data['event']['type'],
        );
        self::assertSame(
            'shop-service',
            $data['event']['source'],
        );
        self::assertSame(
            'ORD-500',
            $data['event']['payload']['orderId'],
        );

        self::assertSame(
            $endpoint->getId(),
            $data['endpoint']['id'],
        );
        self::assertSame(
            'https://example.com/webhook',
            $data['endpoint']['url'],
        );
        self::assertTrue($data['endpoint']['isActive']);

        self::assertArrayHasKey('createdAt', $data);
        self::assertArrayHasKey('createdAt', $data['event']);
        self::assertTrue(
            $client->getResponse()->headers
                ->hasCacheControlDirective('no-store'),
        );
    }

    public function testShowDeliveredDelivery(): void
    {
        $client = static::createClient();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $event = new Event(
            'user.registered',
            'auth-service',
            ['userId' => 'USR-200'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/users',
            ['user.registered'],
        );

        $delivery = new WebhookDelivery($event, $endpoint);
        $delivery->startAttempt();
        $delivery->markDelivered();

        $entityManager->persist($event);
        $entityManager->persist($endpoint);
        $entityManager->persist($delivery);
        $entityManager->flush();

        $client->request(
            'GET',
            '/api/v1/deliveries/' . $delivery->getId(),
            [],
            [],
            self::AUTHORIZATION,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = self::decodeResponse(
            $client->getResponse()->getContent(),
        );

        self::assertSame('delivered', $data['status']);
        self::assertSame(1, $data['attempts']);
        self::assertNotNull($data['deliveredAt']);
        self::assertNull($data['lastError']);
    }

    public function testShowNonexistentDelivery(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/api/v1/deliveries/00000000-0000-7000-8000-000000000000',
            [],
            [],
            self::AUTHORIZATION,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );

        $data = self::decodeResponse(
            $client->getResponse()->getContent(),
        );

        self::assertSame(
            'Webhook delivery not found',
            $data['error'],
        );
    }

    private static function decodeResponse(
        string|false $content,
    ): array {
        return json_decode(
            (string) $content,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
