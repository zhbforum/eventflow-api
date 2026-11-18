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
use Symfony\Component\HttpFoundation\Response;

final class OrganizationEventCreationIsolationTest extends WebTestCase
{
    public function testCreationAndIdempotencyAreTenantScoped(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));
        $subject = 'event-create-' . $suffix;
        $type = 'tenant.created.' . $suffix;

        $organizationA = new Organization(
            'event-create-a-' . $suffix,
            'Organization A',
        );

        $organizationB = new Organization(
            'event-create-b-' . $suffix,
            'Organization B',
        );

        $endpointA = new WebhookEndpoint(
            'https://example.com/tenant-a-' . $suffix,
            [$type],
            $organizationA,
        );

        $endpointB = new WebhookEndpoint(
            'https://example.com/tenant-b-' . $suffix,
            [$type],
            $organizationB,
        );

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
                $endpointA,
                $endpointB,
            ] as $entity
        ) {
            $em->persist($entity);
        }

        $em->flush();

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

        $body = [
            'type' => $type,
            'source' => 'tenant-creation-test',
            'payload' => ['requestId' => $suffix],
        ];

        $key = 'tenant-idempotency-' . $suffix;
        $eventIds = [];

        foreach (
            [
                [$organizationA, $endpointA],
                [$organizationB, $endpointB],
                [$organizationA, $endpointA],
            ] as $index => [$organization, $expectedEndpoint]
        ) {
            $client->jsonRequest(
                'POST',
                '/api/v1/events',
                $body,
                [
                    'HTTP_X_ORGANIZATION_ID' =>
                    $organization->getId(),
                    'HTTP_X_CSRF_TOKEN' => $csrf,
                    'HTTP_IDEMPOTENCY_KEY' => $key,
                ],
            );

            self::assertResponseStatusCodeSame(
                Response::HTTP_CREATED,
            );

            $response = json_decode(
                (string) $client->getResponse()->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            self::assertIsArray($response);
            self::assertIsString($response['id']);

            $eventIds[] = $response['id'];

            self::assertSame(
                $index === 2 ? 'true' : null,
                $client->getResponse()->headers->get(
                    'Idempotency-Replayed',
                ),
            );

            $em->clear();

            $event = $em->find(
                Event::class,
                $response['id'],
            );

            self::assertInstanceOf(Event::class, $event);

            self::assertSame(
                $organization->getId(),
                $event->getOrganizationId(),
            );

            $deliveries = $em
                ->getRepository(WebhookDelivery::class)
                ->findBy(['event' => $event]);

            self::assertCount(1, $deliveries);

            self::assertSame(
                $expectedEndpoint->getId(),
                $deliveries[0]->getEndpoint()->getId(),
            );
        }

        self::assertNotSame($eventIds[0], $eventIds[1]);
        self::assertSame($eventIds[0], $eventIds[2]);
    }
}
