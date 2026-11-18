<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Event;
use App\Entity\Organization;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Service\EventPublisher;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrganizationDeliveryIsolationIntegrationTest extends KernelTestCase
{
    public function testPublisherOnlySchedulesOwnOrganizationEndpoints(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $publisher = $container->get(EventPublisher::class);

        $suffix = bin2hex(random_bytes(8));
        $type = 'tenancy.isolation.' . $suffix;

        $organizationA = new Organization(
            'tenant-a-' . $suffix,
            'Tenant A',
        );

        $organizationB = new Organization(
            'tenant-b-' . $suffix,
            'Tenant B',
        );

        $endpointA = new WebhookEndpoint(
            'https://example.com/tenant-a',
            [$type],
            $organizationA,
        );

        $endpointB = new WebhookEndpoint(
            'https://example.com/tenant-b',
            [$type],
            $organizationB,
        );

        foreach (
            [$organizationA, $organizationB, $endpointA, $endpointB]
            as $entity
        ) {
            $em->persist($entity);
        }

        $em->flush();

        $event = new Event(
            $type,
            'tenancy-integration',
            ['tenant' => 'a'],
            $organizationA,
        );

        $publisher->publish($event);

        $eventId = $event->getId();

        $em->clear();

        $loaded = $em->find(Event::class, $eventId);

        self::assertInstanceOf(Event::class, $loaded);

        self::assertSame(
            $organizationA->getId(),
            $loaded->getOrganizationId(),
        );

        $deliveries = $em
            ->getRepository(WebhookDelivery::class)
            ->findBy(['event' => $loaded]);

        self::assertCount(1, $deliveries);

        self::assertSame(
            $endpointA->getId(),
            $deliveries[0]->getEndpoint()->getId(),
        );

        self::assertSame(
            $organizationA->getId(),
            $deliveries[0]->getEndpoint()->getOrganizationId(),
        );

        $outbox = $em
            ->getRepository(OutboxMessage::class)
            ->findBy(['delivery' => $deliveries[0]]);

        self::assertCount(1, $outbox);
    }

    public function testCrossOrganizationDeliveryIsRejected(): void
    {
        $organizationA = new Organization('tenant-a', 'Tenant A');
        $organizationB = new Organization('tenant-b', 'Tenant B');

        $event = new Event(
            'order.created',
            'test',
            [],
            $organizationA,
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/webhook',
            ['order.created'],
            $organizationB,
        );

        $this->expectException(LogicException::class);

        new WebhookDelivery($event, $endpoint);
    }
}
