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

final class DeliveryReplayEdgeCasesTest extends WebTestCase
{
    private const BEARER = [
        'HTTP_AUTHORIZATION' =>
            'Bearer eventflow-test-management-token',
    ];

    public function testSecondReplayAfterNewFailure(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $delivery = self::createFailedDelivery($em);
        $id = $delivery->getId();

        $url = '/api/v1/deliveries/' . $id . '/replay';

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

        $first = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(1, $first['replayGeneration']);

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        $delivery = $em->find(WebhookDelivery::class, $id);

        self::assertNotNull($delivery);

        // The first manual replay also exhausts its retries.
        $delivery->startAttempt();
        $delivery->markFailed('Replay generation 1 failed');

        $em->flush();

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

        $second = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(2, $second['replayGeneration']);

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        $delivery = $em->find(WebhookDelivery::class, $id);

        self::assertNotNull($delivery);
        self::assertSame('pending', $delivery->getStatus());
        self::assertSame(2, $delivery->getReplayGeneration());

        // Attempts are cumulative, not reset by replay.
        self::assertSame(2, $delivery->getAttempts());

        $outboxes = $em
            ->getRepository(OutboxMessage::class)
            ->findBy(
                ['delivery' => $delivery],
                ['replayGeneration' => 'ASC'],
            );

        self::assertCount(3, $outboxes);

        self::assertSame(
            [0, 1, 2],
            array_map(
                static fn (OutboxMessage $outbox): int =>
                    $outbox->getReplayGeneration(),
                $outboxes,
            ),
        );

        $history = $em
            ->getRepository(WebhookDeliveryReplay::class)
            ->findBy(
                ['delivery' => $delivery],
                ['replayGeneration' => 'ASC'],
            );

        self::assertCount(2, $history);

        self::assertSame(
            'Webhook returned HTTP 503',
            $history[0]->getPreviousError(),
        );

        self::assertSame(
            'Replay generation 1 failed',
            $history[1]->getPreviousError(),
        );

        self::assertNotSame(
            $history[0]->getId(),
            $history[1]->getId(),
        );
    }

    public function testInactiveEndpointCannotBeReplayed(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $delivery = self::createFailedDelivery($em);
        $id = $delivery->getId();

        $endpoint = $delivery->getEndpoint();

        $endpoint->update(
            $endpoint->getUrl(),
            $endpoint->getSubscribedEvents(),
            false,
        );

        $em->flush();

        $client->request(
            'POST',
            '/api/v1/deliveries/' . $id . '/replay',
            [],
            [],
            self::BEARER,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CONFLICT,
        );

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        $delivery = $em->find(WebhookDelivery::class, $id);

        self::assertNotNull($delivery);
        self::assertSame('failed', $delivery->getStatus());
        self::assertSame(0, $delivery->getReplayGeneration());

        self::assertCount(
            1,
            $em->getRepository(OutboxMessage::class)
                ->findBy(['delivery' => $delivery]),
        );

        self::assertCount(
            0,
            $em->getRepository(WebhookDeliveryReplay::class)
                ->findBy(['delivery' => $delivery]),
        );
    }

    private static function createFailedDelivery(
        EntityManagerInterface $em,
    ): WebhookDelivery {
        $event = new Event(
            'order.created',
            'replay-edge-cases',
            ['orderId' => 'ORD-REPLAY-EDGE'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/hooks',
            ['order.created'],
        );

        $delivery = new WebhookDelivery(
            $event,
            $endpoint,
        );

        $delivery->startAttempt();
        $delivery->markFailed('Webhook returned HTTP 503');

        $outbox = new OutboxMessage($delivery);
        $outbox->markPublished();

        foreach (
            [$event, $endpoint, $delivery, $outbox] as $entity
        ) {
            $em->persist($entity);
        }

        $em->flush();

        return $delivery;
    }
}
