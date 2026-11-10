<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Message\DeliverWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class EventControllerTest extends WebTestCase
{
    public function testCreateEvent(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/v1/events', [
            'type' => 'order.created',
            'source' => 'shop-service',
            'payload' => ['orderId' => 'ORD-1042'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('order.created', $data['type']);
        self::assertSame('shop-service', $data['source']);
        self::assertSame('ORD-1042', $data['payload']['orderId']);
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('createdAt', $data);
        self::assertSame(
            '/api/v1/events/' . $data['id'],
            $client->getResponse()->headers->get('Location'),
        );
    }


    public function testEventQueuesDeliveryForMatchingWebhook(): void
    {
        $client = static::createClient();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $matchingEndpoint = new WebhookEndpoint(
            'https://example.com/orders',
            ['order.created'],
        );

        $otherEndpoint = new WebhookEndpoint(
            'https://example.com/users',
            ['user.registered'],
        );

        $entityManager->persist($matchingEndpoint);
        $entityManager->persist($otherEndpoint);
        $entityManager->flush();

        $client->jsonRequest('POST', '/api/v1/events', [
            'type' => 'order.created',
            'source' => 'shop-service',
            'payload' => ['orderId' => 'ORD-2001'],
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

        $event = $entityManager
            ->getRepository(Event::class)
            ->find($data['id']);

        self::assertNotNull($event);

        $deliveries = $entityManager
            ->getRepository(WebhookDelivery::class)
            ->findBy(['event' => $event]);

        self::assertCount(1, $deliveries);

        $delivery = $deliveries[0];

        self::assertSame(
            $matchingEndpoint->getId(),
            $delivery->getEndpoint()->getId(),
        );

        self::assertSame('pending', $delivery->getStatus());
        self::assertSame(0, $delivery->getAttempts());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()
            ->get('messenger.transport.async');

        $messages = $transport->getSent();

        self::assertCount(1, $messages);

        $message = $messages[0]->getMessage();

        self::assertInstanceOf(
            DeliverWebhookMessage::class,
            $message,
        );

        self::assertSame(
            $delivery->getId(),
            $message->deliveryId,
        );
    }


    public function testRetrieveEvent(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/v1/events', [
            'type' => 'user.registered',
            'source' => 'auth-service',
            'payload' => ['userId' => 'USR-123'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $created = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $client->request(
            'GET',
            '/api/v1/events/' . $created['id'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $retrieved = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($created, $retrieved);
    }

    public function testRetrieveNonexistentEvent(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/api/v1/events/00000000-0000-7000-8000-000000000000',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCreateEventWithInvalidData(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/v1/events', [
            'type' => '',
            'source' => 'shop-service',
            'payload' => [],
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

        self::assertSame(422, $data['status']);
        self::assertSame(
            'type',
            $data['violations'][0]['propertyPath'],
        );
    }
}
