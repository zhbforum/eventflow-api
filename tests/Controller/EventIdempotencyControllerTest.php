<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Service\EventRequestFingerprint;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class EventIdempotencyControllerTest extends WebTestCase
{
    private const AUTH = [
        'HTTP_AUTHORIZATION' =>
            'Bearer eventflow-test-management-token',
    ];

    public function testIdenticalRequestsCreateOnlyOneEvent(): void
    {
        $client = static::createClient([], self::AUTH);

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/idempotency',
            ['invoice.created'],
        );

        $em->persist($endpoint);
        $em->flush();

        $key = 'invoice-idempotency-001';

        $body = [
            'type' => 'invoice.created',
            'source' => 'billing-service',
            'payload' => [
                'invoiceId' => 'INV-001',
                'metadata' => [
                    'region' => 'eu',
                    'channel' => 'web',
                ],
            ],
        ];

        $client->jsonRequest(
            'POST',
            '/api/v1/events',
            $body,
            ['HTTP_IDEMPOTENCY_KEY' => $key],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        $first = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $eventId = $first['id'];

        self::assertNull(
            $client->getResponse()->headers->get(
                'Idempotency-Replayed',
            ),
        );

        $reorderedBody = [
            'source' => 'billing-service',
            'type' => 'invoice.created',
            'payload' => [
                'metadata' => [
                    'channel' => 'web',
                    'region' => 'eu',
                ],
                'invoiceId' => 'INV-001',
            ],
        ];

        $client->jsonRequest(
            'POST',
            '/api/v1/events',
            $reorderedBody,
            ['HTTP_IDEMPOTENCY_KEY' => $key],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        self::assertSame(
            'true',
            $client->getResponse()->headers->get(
                'Idempotency-Replayed',
            ),
        );

        self::assertSame(
            '/api/v1/events/' . $eventId,
            $client->getResponse()->headers->get('Location'),
        );

        $second = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($first, $second);

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        $events = $em->getRepository(Event::class)->findBy([
            'idempotencyKeyHash' => EventRequestFingerprint::keyHash('management-token', $key),
        ]);

        self::assertCount(1, $events);
        self::assertSame($eventId, $events[0]->getId());
        self::assertNotNull($events[0]->getRequestHash());

        $deliveries = $em
            ->getRepository(WebhookDelivery::class)
            ->findBy(['event' => $events[0]]);

        self::assertCount(1, $deliveries);

        $outboxes = $em
            ->getRepository(OutboxMessage::class)
            ->findBy(['delivery' => $deliveries[0]]);

        self::assertCount(1, $outboxes);

        self::assertNull($outboxes[0]->getPublishedAt());
    }

    public function testDifferentRequestWithSameKeyReturnsConflict(): void
    {
        $client = static::createClient([], self::AUTH);

        $key = 'invoice-idempotency-conflict';

        $body = [
            'type' => 'invoice.created',
            'source' => 'billing-service',
            'payload' => [
                'items' => ['A', 'B'],
            ],
        ];

        $client->jsonRequest(
            'POST',
            '/api/v1/events',
            $body,
            ['HTTP_IDEMPOTENCY_KEY' => $key],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        $first = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $conflicts = [
            [
                'type' => 'invoice.created',
                'source' => 'billing-service',
                'payload' => [
                    'items' => ['B', 'A'],
                ],
            ],
            [
                'type' => 'invoice.cancelled',
                'source' => 'billing-service',
                'payload' => [
                    'items' => ['A', 'B'],
                ],
            ],
            [
                'type' => 'invoice.created',
                'source' => 'another-service',
                'payload' => [
                    'items' => ['A', 'B'],
                ],
            ],
        ];

        foreach ($conflicts as $conflictingBody) {
            $client->jsonRequest(
                'POST',
                '/api/v1/events',
                $conflictingBody,
                ['HTTP_IDEMPOTENCY_KEY' => $key],
            );

            self::assertResponseStatusCodeSame(
                Response::HTTP_CONFLICT,
            );
        }

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        $events = $em->getRepository(Event::class)->findBy([
            'idempotencyKeyHash' => EventRequestFingerprint::keyHash('management-token', $key),
        ]);

        self::assertCount(1, $events);
        self::assertSame($first['id'], $events[0]->getId());

        $client->jsonRequest(
            'POST',
            '/api/v1/events',
            $body,
            ['HTTP_IDEMPOTENCY_KEY' => $key],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        self::assertSame(
            'true',
            $client->getResponse()->headers->get(
                'Idempotency-Replayed',
            ),
        );
    }

    public function testJsonObjectAndArrayAreDifferentRequests(): void
    {
        $client = static::createClient([], self::AUTH);

        $key = 'json-shape-' . bin2hex(random_bytes(8));

        $objectBody = json_encode(
            [
                'type' => 'invoice.created',
                'source' => 'json-shape-test',
                'payload' => (object) [
                    '0' => 'A',
                    '1' => 'B',
                ],
            ],
            JSON_THROW_ON_ERROR,
        );

        $arrayBody = json_encode(
            [
                'type' => 'invoice.created',
                'source' => 'json-shape-test',
                'payload' => ['A', 'B'],
            ],
            JSON_THROW_ON_ERROR,
        );

        self::assertNotSame($objectBody, $arrayBody);

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ];

        $client->request(
            'POST',
            '/api/v1/events',
            [],
            [],
            $headers,
            $objectBody,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        $client->request(
            'POST',
            '/api/v1/events',
            [],
            [],
            $headers,
            $arrayBody,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CONFLICT,
        );
    }
    public function testInvalidIdempotencyKeysAreRejected(): void
    {
        $client = static::createClient([], self::AUTH);

        $body = [
            'type' => 'invoice.created',
            'source' => 'billing-service',
            'payload' => ['invoiceId' => 'INV-INVALID'],
        ];

        foreach (
            [
                '',
                'invalid key with spaces',
                str_repeat('x', 256),
            ] as $key
        ) {
            $client->jsonRequest(
                'POST',
                '/api/v1/events',
                $body,
                ['HTTP_IDEMPOTENCY_KEY' => $key],
            );

            self::assertResponseStatusCodeSame(
                Response::HTTP_BAD_REQUEST,
            );
        }

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        self::assertCount(
            0,
            $em->getRepository(Event::class)->findBy([
                'source' => 'billing-service',
                'type' => 'invoice.created',
            ]),
        );
    }
}
