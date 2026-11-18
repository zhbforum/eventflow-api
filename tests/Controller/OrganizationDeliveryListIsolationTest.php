<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrganizationDeliveryListIsolationTest extends WebTestCase
{
    public function testDeliveryListIsScopedToOrganization(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));
        $subject = 'delivery-list-user-' . $suffix;

        $organizationA = new Organization(
            'delivery-a-' . $suffix,
            'Organization A',
        );

        $organizationB = new Organization(
            'delivery-b-' . $suffix,
            'Organization B',
        );

        $membership = new OrganizationMembership(
            $organizationA,
            $subject,
            'viewer',
        );

        $eventA = new Event(
            'tenant.delivery.' . $suffix,
            'integration-test',
            ['tenant' => 'a'],
            $organizationA,
        );

        $eventB = new Event(
            'tenant.delivery.' . $suffix,
            'integration-test',
            ['tenant' => 'b'],
            $organizationB,
        );

        $endpointA = new WebhookEndpoint(
            'https://example.com/delivery-a-' . $suffix,
            ['tenant.delivery.' . $suffix],
            $organizationA,
        );

        $endpointB = new WebhookEndpoint(
            'https://example.com/delivery-b-' . $suffix,
            ['tenant.delivery.' . $suffix],
            $organizationB,
        );

        $deliveryA = new WebhookDelivery($eventA, $endpointA);
        $deliveryB = new WebhookDelivery($eventB, $endpointB);

        foreach (
            [
                $organizationA,
                $organizationB,
                $membership,
                $eventA,
                $eventB,
                $endpointA,
                $endpointB,
                $deliveryA,
                $deliveryB,
            ] as $entity
        ) {
            $em->persist($entity);
        }

        $em->flush();

        $client->loginUser(
            new OidcUser(
                $subject,
                'delivery-list-user',
                null,
                ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
                time(),
            ),
            'main',
        );

        $headers = [
            'HTTP_X_ORGANIZATION_ID' => $organizationA->getId(),
        ];

        $client->request(
            'GET',
            '/api/v1/deliveries?eventId=' . $eventA->getId(),
            [],
            [],
            $headers,
        );

        self::assertResponseIsSuccessful();

        $ownData = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($ownData);
        self::assertCount(1, $ownData['items']);
        self::assertSame($deliveryA->getId(), $ownData['items'][0]['id']);
        self::assertSame(1, $ownData['pagination']['total']);

        $client->request(
            'GET',
            '/api/v1/deliveries?eventId=' . $eventB->getId(),
            [],
            [],
            $headers,
        );

        self::assertResponseIsSuccessful();

        $foreignData = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($foreignData);
        self::assertSame([], $foreignData['items']);
        self::assertSame(0, $foreignData['pagination']['total']);

        $client->request(
            'GET',
            '/api/v1/deliveries?endpointId=' . $endpointB->getId(),
            [],
            [],
            $headers,
        );

        self::assertResponseIsSuccessful();

        $endpointData = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($endpointData);
        self::assertSame([], $endpointData['items']);
        self::assertSame(0, $endpointData['pagination']['total']);
    }
}
