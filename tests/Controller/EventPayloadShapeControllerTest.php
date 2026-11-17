<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class EventPayloadShapeControllerTest extends WebTestCase
{
    public function testNumericKeyJsonObjectIsPreserved(): void
    {
        $client = static::createClient([], [
            'HTTP_AUTHORIZATION' =>
            'Bearer eventflow-test-management-token',
        ]);

        $body = json_encode(
            [
                'type' => 'payload.shape.test',
                'source' => 'json-shape-test',
                'payload' => (object) [
                    '0' => 'A',
                    '1' => 'B',
                ],
            ],
            JSON_THROW_ON_ERROR,
        );

        $client->request(
            'POST',
            '/api/v1/events',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' =>
                'shape-' . bin2hex(random_bytes(12)),
            ],
            $body,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        $response = json_decode(
            (string) $client->getResponse()->getContent(),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertInstanceOf(
            stdClass::class,
            $response->payload,
            'A JSON object must not become a JSON array.',
        );

        self::assertSame('A', $response->payload->{'0'});
        self::assertSame('B', $response->payload->{'1'});

        $eventId = $response->id;

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        self::assertSame(
            'object',
            $em->getConnection()->fetchOne(
                'SELECT json_typeof(payload)
                 FROM events
                 WHERE id = :id',
                ['id' => $eventId],
            ),
        );

        $em->clear();

        $client->request(
            'GET',
            '/api/v1/events/' . $eventId,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $reloaded = json_decode(
            (string) $client->getResponse()->getContent(),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertInstanceOf(
            stdClass::class,
            $reloaded->payload,
        );

        self::assertSame('A', $reloaded->payload->{'0'});
        self::assertSame('B', $reloaded->payload->{'1'});
    }
}
