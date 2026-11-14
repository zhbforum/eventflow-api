<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\WebhookEndpoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class WebhookUpdateControllerTest extends WebTestCase
{
    private const AUTHORIZATION = [
        'HTTP_AUTHORIZATION' => 'Bearer eventflow-test-management-token',
    ];

    public function testUpdateRequiresToken(): void
    {
        $client = static::createClient();

        $client->jsonRequest(
            'PATCH',
            '/api/v1/webhooks/00000000-0000-7000-8000-000000000000',
            ['isActive' => false],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testUpdateWebhook(): void
    {
        $client = static::createClient();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $webhook = new WebhookEndpoint(
            'https://example.com/old',
            ['order.created'],
        );

        $entityManager->persist($webhook);
        $entityManager->flush();

        $client->jsonRequest(
            'PATCH',
            '/api/v1/webhooks/' . $webhook->getId(),
            [
                'url' => 'https://example.com/new',
                'subscribedEvents' => ['order.updated'],
                'isActive' => false,
            ],
            self::AUTHORIZATION,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'https://example.com/new',
            $data['url'],
        );
        self::assertSame(
            ['order.updated'],
            $data['subscribedEvents'],
        );
        self::assertFalse($data['isActive']);

        $entityManager->refresh($webhook);

        self::assertSame(
            'https://example.com/new',
            $webhook->getUrl(),
        );
        self::assertFalse($webhook->isActive());
    }

    public function testPartialUpdatePreservesOtherFields(): void
    {
        $client = static::createClient();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $webhook = new WebhookEndpoint(
            'https://example.com/original',
            ['order.created'],
        );

        $entityManager->persist($webhook);
        $entityManager->flush();

        $client->jsonRequest(
            'PATCH',
            '/api/v1/webhooks/' . $webhook->getId(),
            ['isActive' => false],
            self::AUTHORIZATION,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $entityManager->refresh($webhook);

        self::assertFalse($webhook->isActive());
        self::assertSame(
            'https://example.com/original',
            $webhook->getUrl(),
        );
        self::assertSame(
            ['order.created'],
            $webhook->getSubscribedEvents(),
        );
    }

    public function testRejectsInvalidUpdates(): void
    {
        $client = static::createClient();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $webhook = new WebhookEndpoint(
            'https://example.com/webhook',
            ['order.created'],
        );

        $entityManager->persist($webhook);
        $entityManager->flush();

        foreach (
            [
                ['url' => 'http://localhost/webhook'],
                ['url' => 'https://user:password@example.com/hook'],
                ['subscribedEvents' => []],
                ['subscribedEvents' => ['order.created', 'order.created']],
                ['isActive' => 'false'],
                ['isActive' => null],
            ] as $payload
        ) {
            $client->jsonRequest(
                'PATCH',
                '/api/v1/webhooks/' . $webhook->getId(),
                $payload,
                self::AUTHORIZATION,
            );

            self::assertResponseStatusCodeSame(
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $freshEntityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $reloadedWebhook = $freshEntityManager
            ->getRepository(WebhookEndpoint::class)
            ->find($webhook->getId());

        self::assertNotNull($reloadedWebhook);
        self::assertTrue($reloadedWebhook->isActive());
        self::assertSame(
            'https://example.com/webhook',
            $reloadedWebhook->getUrl(),
        );
    }

    public function testUpdateNonexistentWebhook(): void
    {
        $client = static::createClient();

        $client->jsonRequest(
            'PATCH',
            '/api/v1/webhooks/00000000-0000-7000-8000-000000000000',
            ['isActive' => false],
            self::AUTHORIZATION,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
