<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookDeliveryReplay;
use App\Entity\WebhookEndpoint;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class DeliveryReplayRbacTest extends WebTestCase
{
    private const MISSING_ID =
        '00000000-0000-7000-8000-000000000000';

    public function testViewerCannotRequestReplay(): void
    {
        $client = static::createClient();

        $client->loginUser(
            new OidcUser(
                'replay-viewer',
                'viewer',
                'viewer@example.test',
                ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
                time(),
            ),
            'main',
        );

        $client->request('GET', '/api/v1/auth/csrf');

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $csrf = $data['management_csrf_token'];

        self::assertIsString($csrf);

        $client->request(
            'POST',
            '/api/v1/deliveries/'
                . self::MISSING_ID
                . '/replay',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testAdminNeedsCsrfAndIsRecordedInAudit(): void
    {
        $client = static::createClient();

        $client->loginUser(
            new OidcUser(
                'replay-admin-user',
                'admin',
                'admin@example.test',
                ['ROLE_USER', 'ROLE_EVENTFLOW_ADMIN'],
                time(),
            ),
            'main',
        );

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $event = new Event(
            'order.created',
            'replay-rbac-test',
            ['orderId' => 'ORD-RBAC-001'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/replay',
            ['order.created'],
        );

        $delivery = new WebhookDelivery(
            $event,
            $endpoint,
        );

        $delivery->startAttempt();
        $delivery->markFailed('Original HTTP 503');

        $outbox = new OutboxMessage($delivery);
        $outbox->markPublished();

        foreach (
            [$event, $endpoint, $delivery, $outbox] as $record
        ) {
            $em->persist($record);
        }

        $em->flush();

        $deliveryId = $delivery->getId();

        $url = '/api/v1/deliveries/'
            . $deliveryId
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
            ['HTTP_X_CSRF_TOKEN' => 'invalid'],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request('GET', '/api/v1/auth/csrf');

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $csrf = $data['management_csrf_token'];

        self::assertIsString($csrf);

        $client->request(
            'POST',
            $url,
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_ACCEPTED,
        );

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        $savedDelivery = $em->find(
            WebhookDelivery::class,
            $deliveryId,
        );

        self::assertNotNull($savedDelivery);

        self::assertSame(
            'pending',
            $savedDelivery->getStatus(),
        );

        self::assertSame(
            1,
            $savedDelivery->getReplayGeneration(),
        );

        $history = $em
            ->getRepository(WebhookDeliveryReplay::class)
            ->findBy(['delivery' => $savedDelivery]);

        self::assertCount(1, $history);

        self::assertSame(
            'oidc:replay-admin-user',
            $history[0]->getRequestedBy(),
        );

        self::assertSame(
            'Original HTTP 503',
            $history[0]->getPreviousError(),
        );

        self::assertCount(
            2,
            $em->getRepository(OutboxMessage::class)
                ->findBy(['delivery' => $savedDelivery]),
        );
    }
}
