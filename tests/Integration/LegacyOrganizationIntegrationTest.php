<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Organization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LegacyOrganizationIntegrationTest extends KernelTestCase
{
    public function testLegacyOrganizationExists(): void
    {
        self::bootKernel();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $organization = $em
            ->getRepository(Organization::class)
            ->findOneBy(['slug' => 'legacy']);

        self::assertInstanceOf(
            Organization::class,
            $organization,
        );

        self::assertSame(
            '019a8d00-0000-7000-8000-000000000001',
            $organization->getId(),
        );

        self::assertSame(
            'Legacy Organization',
            $organization->getName(),
        );
    }
}
