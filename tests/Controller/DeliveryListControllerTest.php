<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class DeliveryListControllerTest extends WebTestCase
{
    private const AUTHORIZATION = [
        'HTTP_AUTHORIZATION' => 'Bearer eventflow-test-management-token',
    ];

    public function testListRequiresManagementToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/deliveries');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->request(
            'GET',
            '/api/v1/deliveries',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer wrong-token'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testFiltersByStatusAndEvent(): void
    {
        $client = static::createClient();
        $ids = self::seedDeliveries();

        $client->request(
            'GET',
            '/api/v1/deliveries?status=failed&eventId=' . $ids['event1'],
            [],
            [],
            self::AUTHORIZATION,
        );

        self::assertResponseIsSuccessful();

        $data = self::decodeResponse($client->getResponse()->getContent());

        self::assertSame(1, $data['pagination']['total']);
        self::assertCount(1, $data['items']);

        self::assertSame($ids['failed'], $data['items'][0]['id']);
        self::assertSame('failed', $data['items'][0]['status']);
        self::assertSame('Webhook returned HTTP 503', $data['items'][0]['lastError']);
        self::assertSame('order.created', $data['items'][0]['eventType']);
    }

    public function testFiltersByEndpointAndPaginates(): void
    {
        $client = static::createClient();
        $ids = self::seedDeliveries();

        $url = '/api/v1/deliveries?endpointId='
            . $ids['endpoint1']
            . '&limit=1&page=';

        $client->request(
            'GET',
            $url . '1',
            [],
            [],
            self::AUTHORIZATION,
        );

        self::assertResponseIsSuccessful();

        $first = self::decodeResponse($client->getResponse()->getContent());

        self::assertSame(
            ['page' => 1, 'limit' => 1, 'total' => 2, 'pages' => 2],
            $first['pagination'],
        );
        self::assertCount(1, $first['items']);

        $client->request(
            'GET',
            $url . '2',
            [],
            [],
            self::AUTHORIZATION,
        );

        self::assertResponseIsSuccessful();

        $second = self::decodeResponse($client->getResponse()->getContent());

        self::assertCount(1, $second['items']);
        self::assertNotSame(
            $first['items'][0]['id'],
            $second['items'][0]['id'],
        );

        self::assertSame(
            $ids['endpoint1'],
            $second['items'][0]['endpointId'],
        );
    }

    public function testRejectsInvalidFilters(): void
    {
        $client = static::createClient();

        foreach (
            [
                'page=0',
                'limit=101',
                'status=unknown',
                'eventId=not-a-uuid',
                'endpointId=not-a-uuid',
            ] as $query
        ) {
            $client->request(
                'GET',
                '/api/v1/deliveries?' . $query,
                [],
                [],
                self::AUTHORIZATION,
            );

            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function seedDeliveries(): array
    {
        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $event1 = new Event(
            'order.created',
            'shop-service',
            ['orderId' => 'ORD-1'],
        );

        $event2 = new Event(
            'user.registered',
            'auth-service',
            ['userId' => 'USR-1'],
        );

        $endpoint1 = new WebhookEndpoint(
            'https://example.com/orders',
            ['order.created'],
        );

        $endpoint2 = new WebhookEndpoint(
            'https://example.com/backup',
            ['order.created'],
        );

        $delivered = new WebhookDelivery($event1, $endpoint1);
        $delivered->startAttempt();
        $delivered->markDelivered();

        $failed = new WebhookDelivery($event1, $endpoint2);
        $failed->startAttempt();
        $failed->markFailed('Webhook returned HTTP 503');

        $pending = new WebhookDelivery($event2, $endpoint1);

        foreach (
            [
                $event1,
                $event2,
                $endpoint1,
                $endpoint2,
                $delivered,
                $failed,
                $pending,
            ] as $entity
        ) {
            $entityManager->persist($entity);
        }

        $entityManager->flush();

        return [
            'event1' => $event1->getId(),
            'endpoint1' => $endpoint1->getId(),
            'failed' => $failed->getId(),
        ];
    }

    private static function decodeResponse(string|false $content): array
    {
        return json_decode(
            (string) $content,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
