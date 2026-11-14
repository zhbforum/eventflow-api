<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class EventListControllerTest extends WebTestCase
{
    private const AUTHORIZATION = [
        'HTTP_AUTHORIZATION' => 'Bearer eventflow-test-management-token',
    ];

    public function testListRequiresManagementToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/events');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->request(
            'GET',
            '/api/v1/events',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer wrong-token'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testListFiltersAndPaginatesEvents(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $events = [
            new Event('order.created', 'shop', ['order' => 1]),
            new Event('order.created', 'shop', ['order' => 2]),
            new Event('order.created', 'other', ['order' => 3]),
            new Event('user.registered', 'shop', ['user' => 4]),
        ];

        foreach ($events as $event) {
            $entityManager->persist($event);
        }

        $entityManager->flush();

        $client->request(
            'GET',
            '/api/v1/events?type=order.created&source=shop&page=1&limit=1',
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
        self::assertSame('order.created', $first['items'][0]['type']);
        self::assertSame('shop', $first['items'][0]['source']);
        self::assertArrayHasKey('createdAt', $first['items'][0]);

        $client->request(
            'GET',
            '/api/v1/events?type=order.created&source=shop&page=2&limit=1',
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

        self::assertSame(2, $second['pagination']['total']);
        self::assertCount(1, $second['items']);
        self::assertNotSame($first['items'][0]['id'], $second['items'][0]['id']);
    }

    public function testListRejectsInvalidPaginationAndFilters(): void
    {
        $client = static::createClient();

        foreach (['page=0', 'limit=101', 'type=', 'source='] as $query) {
            $client->request(
                'GET',
                '/api/v1/events?' . $query,
                [],
                [],
                self::AUTHORIZATION,
            );

            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        }
    }
}
