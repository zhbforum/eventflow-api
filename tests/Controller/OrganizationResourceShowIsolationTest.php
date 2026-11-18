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
use Symfony\Component\Uid\Uuid;

final class OrganizationResourceShowIsolationTest extends WebTestCase
{
    public function testShowRoutesAreScopedToSelectedOrganization(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(8));
        $subject = 'show-reader-' . $suffix;

        $organizationA = new Organization(
            'show-a-' . $suffix,
            'Show Test Organization A',
        );
        $organizationB = new Organization(
            'show-b-' . $suffix,
            'Show Test Organization B',
        );

        $eventA = new Event(
            'show.test.' . $suffix,
            'tenant-isolation-test',
            ['tenant' => 'a'],
            $organizationA,
        );
        $eventB = new Event(
            'show.test.' . $suffix,
            'tenant-isolation-test',
            ['tenant' => 'b'],
            $organizationB,
        );

        $endpointA = new WebhookEndpoint(
            'https://example.com/show-a-' . $suffix,
            ['show.test.' . $suffix],
            $organizationA,
        );
        $endpointB = new WebhookEndpoint(
            'https://example.com/show-b-' . $suffix,
            ['show.test.' . $suffix],
            $organizationB,
        );

        $deliveryA = new WebhookDelivery($eventA, $endpointA);
        $deliveryB = new WebhookDelivery($eventB, $endpointB);

        foreach (
            [
                $organizationA,
                $organizationB,
                new OrganizationMembership($organizationA, $subject, 'viewer'),
                new OrganizationMembership($organizationB, $subject, 'viewer'),
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
                'show-reader',
                null,
                ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
                time(),
            ),
            'main',
        );

        $resources = [
            '/api/v1/events/' => [$eventA->getId(), $eventB->getId()],
            '/api/v1/webhooks/' => [$endpointA->getId(), $endpointB->getId()],
            '/api/v1/deliveries/' => [$deliveryA->getId(), $deliveryB->getId()],
        ];

        $missingId = Uuid::v7()->toRfc4122();

        foreach ($resources as $prefix => [$idA, $idB]) {
            foreach (
                [
                    [$organizationA->getId(), $idA, $idB],
                    [$organizationB->getId(), $idB, $idA],
                ] as [$organizationId, $ownId, $foreignId]
            ) {
                $headers = [
                    'HTTP_X_ORGANIZATION_ID' => $organizationId,
                ];

                $client->request('GET', $prefix . $ownId, [], [], $headers);

                self::assertResponseStatusCodeSame(Response::HTTP_OK);

                $ownBody = json_decode(
                    (string) $client->getResponse()->getContent(),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );

                self::assertIsArray($ownBody);
                self::assertSame($ownId, $ownBody['id']);

                $client->request('GET', $prefix . $foreignId, [], [], $headers);

                self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

                $foreignBody = (string) $client->getResponse()->getContent();

                $client->request('GET', $prefix . $missingId, [], [], $headers);

                self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
                self::assertSame(
                    $foreignBody,
                    (string) $client->getResponse()->getContent(),
                    'Foreign and missing UUIDs must be indistinguishable.',
                );
            }
        }
    }

    public function testShowRoutesRequireMembershipAndGlobalReadRole(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(8));
        $subject = 'show-role-user-' . $suffix;
        $organization = new Organization(
            'show-role-' . $suffix,
            'Show Role Test Organization',
        );

        $em->persist($organization);
        $em->persist(new OrganizationMembership(
            $organization,
            $subject,
            'viewer',
        ));
        $em->flush();

        $headers = ['HTTP_X_ORGANIZATION_ID' => $organization->getId()];
        $routes = [
            '/api/v1/events/',
            '/api/v1/webhooks/',
            '/api/v1/deliveries/',
        ];
        $missingId = Uuid::v7()->toRfc4122();

        foreach (
            [
                [$subject, ['ROLE_USER']],
                ['non-member-' . $suffix, ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER']],
            ] as [$userId, $roles]
        ) {
            $client->loginUser(
                new OidcUser($userId, $userId, null, $roles, time()),
                'main',
            );

            foreach ($routes as $prefix) {
                $client->request('GET', $prefix . $missingId, [], [], $headers);
                self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            }
        }
    }
}
