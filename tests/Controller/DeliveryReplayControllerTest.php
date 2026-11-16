<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookDeliveryReplay;
use App\Entity\WebhookEndpoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class DeliveryReplayControllerTest extends WebTestCase
{
    private const BEARER = [
        'HTTP_AUTHORIZATION' =>
            'Bearer eventflow-test-management-token',
    ];

    public function testFailedDeliveryCanBeReplayedOnce(): void
    {
        $client = static::createClient();

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $event = new Event(
            'order.created',
            'replay-api-test',
            ['orderId' => 'ORD-REPLAY-001'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/hooks',
            ['order.created'],
        );

        $delivery = new WebhookDelivery($event, $endpoint);
        $delivery->startAttempt();
        $delivery->markFailed('Webhook returned HTTP 503');

        $originalOutbox = new OutboxMessage($delivery);

        $entityManager->persist($event);
        $entityManager->persist($endpoint);
        $entityManager->persist($delivery);
        $entityManager->persist($originalOutbox);
        $entityManager->flush();

        $deliveryId = $delivery->getId();
        $originalOutboxId = $originalOutbox->getId();

        $url = '/api/v1/deliveries/'
            . $deliveryId
            . '/replay';

        $client->request(
            'POST',
            $url,
            [],
            [],
            self::BEARER,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_ACCEPTED,
        );

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($deliveryId, $data['deliveryId']);
        self::assertSame(1, $data['replayGeneration']);
        self::assertSame('pending', $data['status']);
        self::assertNotEmpty($data['replayId']);

        self::assertTrue(
            $client->getResponse()
                ->headers
                ->hasCacheControlDirective('no-store'),
        );

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $entityManager->clear();

        $savedDelivery = $entityManager->find(
            WebhookDelivery::class,
            $deliveryId,
        );

        self::assertNotNull($savedDelivery);
        self::assertSame('pending', $savedDelivery->getStatus());
        self::assertSame(1, $savedDelivery->getAttempts());
        self::assertSame(1, $savedDelivery->getReplayGeneration());
        self::assertNull($savedDelivery->getLastError());

        $outboxes = $entityManager
            ->getRepository(OutboxMessage::class)
            ->findBy(
                ['delivery' => $savedDelivery],
                ['replayGeneration' => 'ASC'],
            );

        self::assertCount(2, $outboxes);

        self::assertSame(
            $originalOutboxId,
            $outboxes[0]->getId(),
        );

        self::assertSame(0, $outboxes[0]->getReplayGeneration());
        self::assertSame(1, $outboxes[1]->getReplayGeneration());
        self::assertNull($outboxes[1]->getPublishedAt());

        $replays = $entityManager
            ->getRepository(WebhookDeliveryReplay::class)
            ->findBy(['delivery' => $savedDelivery]);

        self::assertCount(1, $replays);

        self::assertSame(
            $data['replayId'],
            $replays[0]->getId(),
        );

        self::assertSame(
            'management-token',
            $replays[0]->getRequestedBy(),
        );

        self::assertSame(
            'Webhook returned HTTP 503',
            $replays[0]->getPreviousError(),
        );

        $client->request(
            'POST',
            $url,
            [],
            [],
            self::BEARER,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CONFLICT,
        );

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $entityManager->clear();

        self::assertCount(
            2,
            $entityManager
                ->getRepository(OutboxMessage::class)
                ->findBy(['delivery' => $savedDelivery->getId()]),
        );
    }

    public function testReplayRequiresAuthorization(): void
    {
        $client = static::createClient();

        $url = '/api/v1/deliveries/'
            . '00000000-0000-7000-8000-000000000000'
            . '/replay';

        $client->request('POST', $url);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request(
            'POST',
            $url,
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer invalid'],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request(
            'POST',
            $url,
            [],
            [],
            self::BEARER,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );
    }
}
