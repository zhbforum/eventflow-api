<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WebhookEndpoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class WebhookListControllerTest extends WebTestCase
{
    private const AUTHORIZATION = [
        'HTTP_AUTHORIZATION' => 'Bearer eventflow-test-management-token',
    ];

    public function testListRequiresManagementToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/webhooks');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->request(
            'GET',
            '/api/v1/webhooks',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer wrong-token'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testListFiltersAndPaginatesWebhooks(): void
    {
        $client = static::createClient();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $webhooks = [
            new WebhookEndpoint(
                'https://hooks.example.com/orders',
                ['order.created'],
            ),
            new WebhookEndpoint(
                'https://hooks.example.com/users',
                ['user.registered'],
            ),
            new WebhookEndpoint(
                'https://other.example.com/orders',
                ['order.created'],
            ),
        ];

        foreach ($webhooks as $webhook) {
            $entityManager->persist($webhook);
        }

        $entityManager->flush();

        $client->request(
            'GET',
            '/api/v1/webhooks?url=hooks.example.com&page=1&limit=1',
            [],
            [],
            self::AUTHORIZATION,
        );

        self::assertResponseIsSuccessful();

        $first = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ['page' => 1, 'limit' => 1, 'total' => 2, 'pages' => 2],
            $first['pagination'],
        );

        self::assertCount(1, $first['items']);
        self::assertTrue($first['items'][0]['isActive']);
        self::assertArrayHasKey('subscribedEvents', $first['items'][0]);

        $client->request(
            'GET',
            '/api/v1/webhooks?url=hooks.example.com&page=2&limit=1',
            [],
            [],
            self::AUTHORIZATION,
        );

        self::assertResponseIsSuccessful();

        $second = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertCount(1, $second['items']);
        self::assertNotSame(
            $first['items'][0]['id'],
            $second['items'][0]['id'],
        );
    }

    public function testListFiltersByActivity(): void
    {
        $client = static::createClient();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $active = new WebhookEndpoint(
            'https://example.com/active',
            ['order.created'],
        );

        $inactive = new WebhookEndpoint(
            'https://example.com/inactive',
            ['order.created'],
        );

        $entityManager->persist($active);
        $entityManager->persist($inactive);
        $entityManager->flush();

        $entityManager->getConnection()->executeStatement(
            'UPDATE webhook_endpoints SET is_active = FALSE WHERE id = :id',
            ['id' => $inactive->getId()],
        );

        $entityManager->clear();

        $client->request(
            'GET',
            '/api/v1/webhooks?active=false',
            [],
            [],
            self::AUTHORIZATION,
        );

        self::assertResponseIsSuccessful();

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(1, $data['pagination']['total']);
        self::assertSame($inactive->getId(), $data['items'][0]['id']);
        self::assertFalse($data['items'][0]['isActive']);
    }

    public function testRejectsInvalidFilters(): void
    {
        $client = static::createClient();

        foreach (['page=0', 'limit=101', 'active=invalid', 'url='] as $query) {
            $client->request(
                'GET',
                '/api/v1/webhooks?' . $query,
                [],
                [],
                self::AUTHORIZATION,
            );

            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        }
    }
}
