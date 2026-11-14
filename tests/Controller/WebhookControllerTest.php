<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class WebhookControllerTest extends WebTestCase
{
    public function testCreateWebhook(): void
    {
        $client = static::createClient(
            [],
            [
                'HTTP_AUTHORIZATION' =>
                'Bearer eventflow-test-management-token',
            ],
        );

        $client->jsonRequest('POST', '/api/v1/webhooks', [
            'url' => 'https://example.com/webhooks',
            'subscribedEvents' => [
                'order.created',
                'order.updated',
            ],
        ]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'https://example.com/webhooks',
            $data['url'],
        );
        self::assertSame(
            ['order.created', 'order.updated'],
            $data['subscribedEvents'],
        );
        self::assertTrue($data['isActive']);
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('createdAt', $data);
        self::assertSame(
            '/api/v1/webhooks/' . $data['id'],
            $client->getResponse()->headers->get('Location'),
        );
    }

    public function testRetrieveWebhook(): void
    {
        $client = static::createClient(
            [],
            [
                'HTTP_AUTHORIZATION' =>
                'Bearer eventflow-test-management-token',
            ],
        );

        $client->jsonRequest('POST', '/api/v1/webhooks', [
            'url' => 'https://example.com/webhooks',
            'subscribedEvents' => ['user.registered'],
        ]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        $created = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $client->request(
            'GET',
            '/api/v1/webhooks/' . $created['id'],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $retrieved = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($created, $retrieved);
    }

    public function testRetrieveNonexistentWebhook(): void
    {
        $client = static::createClient(
            [],
            [
                'HTTP_AUTHORIZATION' =>
                'Bearer eventflow-test-management-token',
            ],
        );

        $client->request(
            'GET',
            '/api/v1/webhooks/00000000-0000-7000-8000-000000000000',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );
    }

    public function testRejectInsecureUrl(): void
    {
        $client = static::createClient(
            [],
            [
                'HTTP_AUTHORIZATION' =>
                'Bearer eventflow-test-management-token',
            ],
        );

        $client->jsonRequest('POST', '/api/v1/webhooks', [
            'url' => 'http://localhost:8080/webhook',
            'subscribedEvents' => ['order.created'],
        ]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'url',
            $data['violations'][0]['propertyPath'],
        );
    }

    public function testRejectDuplicateSubscriptions(): void
    {
        $client = static::createClient(
            [],
            [
                'HTTP_AUTHORIZATION' =>
                'Bearer eventflow-test-management-token',
            ],
        );

        $client->jsonRequest('POST', '/api/v1/webhooks', [
            'url' => 'https://example.com/webhooks',
            'subscribedEvents' => [
                'order.created',
                'order.created',
            ],
        ]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'subscribedEvents',
            $data['violations'][0]['propertyPath'],
        );
    }


    public function testRejectEmptySubscriptions(): void
    {
        $client = static::createClient(
            [],
            [
                'HTTP_AUTHORIZATION' =>
                'Bearer eventflow-test-management-token',
            ],
        );

        $client->jsonRequest('POST', '/api/v1/webhooks', [
            'url' => 'https://example.com/webhooks',
            'subscribedEvents' => [],
        ]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public function testRejectInvalidEventType(): void
    {
        $client = static::createClient(
            [],
            [
                'HTTP_AUTHORIZATION' =>
                'Bearer eventflow-test-management-token',
            ],
        );

        $client->jsonRequest('POST', '/api/v1/webhooks', [
            'url' => 'https://example.com/webhooks',
            'subscribedEvents' => ['INVALID EVENT'],
        ]);

        self::assertResponseStatusCodeSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
