<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrganizationEventListIsolationTest extends WebTestCase
{
    public function testEventListOnlyReturnsCurrentOrganizationEvents(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));
        $type = 'tenant.list.' . $suffix;
        $subject = 'tenant-list-user-' . $suffix;

        $organizationA = new Organization(
            'list-a-' . $suffix,
            'Organization A',
        );

        $organizationB = new Organization(
            'list-b-' . $suffix,
            'Organization B',
        );

        $membership = new OrganizationMembership(
            $organizationA,
            $subject,
            'viewer',
        );

        $eventA = new Event(
            $type,
            'integration-test',
            ['tenant' => 'a'],
            $organizationA,
        );

        $eventB = new Event(
            $type,
            'integration-test',
            ['tenant' => 'b'],
            $organizationB,
        );

        foreach (
            [
                $organizationA,
                $organizationB,
                $membership,
                $eventA,
                $eventB,
            ] as $entity
        ) {
            $em->persist($entity);
        }

        $em->flush();

        $client->loginUser(
            new OidcUser(
                $subject,
                'tenant-list-user',
                null,
                ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
                time(),
            ),
            'main',
        );

        $client->request(
            'GET',
            '/api/v1/events?type=' . $type,
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
            $eventA->getId(),
            $data['items'][0]['id'],
        );
        self::assertSame(1, $data['pagination']['total']);
    }
}
