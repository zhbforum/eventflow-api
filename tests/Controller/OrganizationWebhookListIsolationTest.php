<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\WebhookEndpoint;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrganizationWebhookListIsolationTest extends WebTestCase
{
    public function testWebhookListOnlyReturnsOwnOrganization(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));
        $subject = 'webhook-list-user-' . $suffix;

        $organizationA = new Organization(
            'webhook-list-a-' . $suffix,
            'Organization A',
        );

        $organizationB = new Organization(
            'webhook-list-b-' . $suffix,
            'Organization B',
        );

        $membership = new OrganizationMembership(
            $organizationA,
            $subject,
            'viewer',
        );

        $endpointA = new WebhookEndpoint(
            'https://example.com/tenancy-' . $suffix . '/a',
            ['order.created'],
            $organizationA,
        );

        $endpointB = new WebhookEndpoint(
            'https://example.com/tenancy-' . $suffix . '/b',
            ['order.created'],
            $organizationB,
        );

        foreach (
            [
                $organizationA,
                $organizationB,
                $membership,
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
                'webhook-list-user',
                null,
                ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
                time(),
            ),
            'main',
        );

        $client->request(
            'GET',
            '/api/v1/webhooks?url=tenancy-' . $suffix,
            [],
            [],
            [
                'HTTP_X_ORGANIZATION_ID' =>
                    $organizationA->getId(),
            ],
        );

        self::assertResponseIsSuccessful();

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($data);
        self::assertCount(1, $data['items']);

        self::assertSame(
            $endpointA->getId(),
            $data['items'][0]['id'],
        );

        self::assertSame(
            1,
            $data['pagination']['total'],
        );
    }
}
