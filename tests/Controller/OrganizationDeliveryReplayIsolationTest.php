<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookDeliveryReplay;
use App\Entity\WebhookEndpoint;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class OrganizationDeliveryReplayIsolationTest extends WebTestCase
{
    public function testReplayIsTenantScopedAndDoesNotMutateForeignDelivery(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));
        $subject = 'replay-tenant-' . $suffix;

        $organizationA = new Organization(
            'replay-a-' . $suffix,
            'Replay Organization A',
        );

        $organizationB = new Organization(
            'replay-b-' . $suffix,
            'Replay Organization B',
        );

        $eventA = new Event(
            'order.created',
            'replay-tenant-test',
            ['tenant' => 'a'],
            $organizationA,
        );

        $eventB = new Event(
            'order.created',
            'replay-tenant-test',
            ['tenant' => 'b'],
            $organizationB,
        );

        $endpointA = new WebhookEndpoint(
            'https://example.com/replay-a-' . $suffix,
            ['order.created'],
            $organizationA,
        );

        $endpointB = new WebhookEndpoint(
            'https://example.com/replay-b-' . $suffix,
            ['order.created'],
            $organizationB,
        );

        $deliveryA = new WebhookDelivery($eventA, $endpointA);
        $deliveryB = new WebhookDelivery($eventB, $endpointB);

        $deliveryA->startAttempt();
        $deliveryA->markFailed('Tenant A failure');

        $deliveryB->startAttempt();
        $deliveryB->markFailed('Tenant B failure');

        $outboxA = new OutboxMessage($deliveryA);
        $outboxB = new OutboxMessage($deliveryB);

        $outboxA->markPublished();
        $outboxB->markPublished();

        foreach (
            [
                $organizationA,
                $organizationB,
                new OrganizationMembership(
                    $organizationA,
                    $subject,
                    'admin',
                ),
                new OrganizationMembership(
                    $organizationB,
                    $subject,
                    'admin',
                ),
                $eventA,
                $eventB,
                $endpointA,
                $endpointB,
                $deliveryA,
                $deliveryB,
                $outboxA,
                $outboxB,
            ] as $entity
        ) {
            $em->persist($entity);
        }

        $em->flush();

        $organizationAId = $organizationA->getId();
        $organizationBId = $organizationB->getId();

        $deliveryAId = $deliveryA->getId();
        $deliveryBId = $deliveryB->getId();

        $client->loginUser(
            new OidcUser(
                $subject,
                $subject,
                null,
                ['ROLE_USER', 'ROLE_EVENTFLOW_ADMIN'],
                time(),
            ),
            'main',
        );

        $client->request('GET', '/api/v1/auth/csrf');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $csrfData = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($csrfData);

        $csrf = $csrfData['management_csrf_token'];

        self::assertIsString($csrf);

        $headersA = [
            'HTTP_X_ORGANIZATION_ID' => $organizationAId,
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ];

        $headersB = [
            'HTTP_X_ORGANIZATION_ID' => $organizationBId,
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ];

        $client->request(
            'POST',
            '/api/v1/deliveries/' . $deliveryBId . '/replay',
            [],
            [],
            $headersA,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );

        $foreignResponse = (string) $client
            ->getResponse()
            ->getContent();

        $client->request(
            'POST',
            '/api/v1/deliveries/'
                . Uuid::v7()->toRfc4122()
                . '/replay',
            [],
            [],
            $headersA,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );

        self::assertSame(
            $foreignResponse,
            (string) $client->getResponse()->getContent(),
        );

        $em->clear();

        $savedB = $em->find(
            WebhookDelivery::class,
            $deliveryBId,
        );

        self::assertInstanceOf(WebhookDelivery::class, $savedB);

        self::assertSame('failed', $savedB->getStatus());
        self::assertSame(0, $savedB->getReplayGeneration());
        self::assertSame('Tenant B failure', $savedB->getLastError());

        self::assertCount(
            1,
            $em->getRepository(OutboxMessage::class)
                ->findBy(['delivery' => $savedB]),
        );

        self::assertCount(
            0,
            $em->getRepository(WebhookDeliveryReplay::class)
                ->findBy(['delivery' => $savedB]),
        );

        $client->request(
            'POST',
            '/api/v1/deliveries/' . $deliveryAId . '/replay',
            [],
            [],
            $headersA,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_ACCEPTED,
        );

        $acceptedA = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($acceptedA);
        self::assertSame($deliveryAId, $acceptedA['deliveryId']);
        self::assertSame(1, $acceptedA['replayGeneration']);

        $client->request(
            'POST',
            '/api/v1/deliveries/' . $deliveryAId . '/replay',
            [],
            [],
            $headersA,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CONFLICT,
        );

        $client->request(
            'POST',
            '/api/v1/deliveries/' . $deliveryBId . '/replay',
            [],
            [],
            $headersB,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_ACCEPTED,
        );

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        foreach ([$deliveryAId, $deliveryBId] as $id) {
            $delivery = $em->find(WebhookDelivery::class, $id);

            self::assertInstanceOf(
                WebhookDelivery::class,
                $delivery,
            );

            self::assertSame('pending', $delivery->getStatus());
            self::assertSame(1, $delivery->getReplayGeneration());

            self::assertCount(
                2,
                $em->getRepository(OutboxMessage::class)
                    ->findBy(['delivery' => $delivery]),
            );

            $history = $em
                ->getRepository(WebhookDeliveryReplay::class)
                ->findBy(['delivery' => $delivery]);

            self::assertCount(1, $history);

            self::assertSame(
                'oidc:' . $subject,
                $history[0]->getRequestedBy(),
            );
        }
    }
}
