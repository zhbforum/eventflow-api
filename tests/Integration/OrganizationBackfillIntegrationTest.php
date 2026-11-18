<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Event;
use App\Entity\WebhookEndpoint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrganizationBackfillIntegrationTest extends KernelTestCase
{
    private const LEGACY_ID =
        '019a8d00-0000-7000-8000-000000000001';

    public function testExistingCreationPathsUseLegacyOrganization(): void
    {
        self::bootKernel();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $event = new Event(
            'tenancy.backfill.test',
            'integration-test',
            ['test' => true],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/tenancy-test',
            ['tenancy.backfill.test'],
        );

        $em->persist($event);
        $em->persist($endpoint);
        $em->flush();

        $connection = $em->getConnection();

        foreach (
            [
                'events' => $event->getId(),
                'webhook_endpoints' => $endpoint->getId(),
            ] as $table => $id
        ) {
            $organizationId = $connection->fetchOne(
                "SELECT organization_id
                 FROM $table
                 WHERE id = :id",
                ['id' => $id],
            );

            self::assertSame(
                self::LEGACY_ID,
                $organizationId,
            );
        }
    }

    public function testOrganizationForeignKeysExist(): void
    {
        self::bootKernel();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $constraints = $em->getConnection()->fetchAllKeyValue(
            "SELECT
                conrelid::regclass::text,
                confrelid::regclass::text
             FROM pg_constraint
             WHERE conname IN (
                'fk_events_organization',
                'fk_webhook_endpoints_organization'
             )
             AND contype = 'f'
             AND convalidated = true",
        );

        self::assertSame(
            'organizations',
            $constraints['events'] ?? null,
        );

        self::assertSame(
            'organizations',
            $constraints['webhook_endpoints'] ?? null,
        );
    }
}
