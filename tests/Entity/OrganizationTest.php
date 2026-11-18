<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Organization;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OrganizationTest extends TestCase
{
    public function testOrganizationCreation(): void
    {
        $organization = new Organization(
            'acme',
            ' Acme Corporation ',
        );

        self::assertTrue(Uuid::isValid(
            $organization->getId(),
        ));

        self::assertSame(
            'acme',
            $organization->getSlug(),
        );

        self::assertSame(
            'Acme Corporation',
            $organization->getName(),
        );

        self::assertSame(
            '+00:00',
            $organization->getCreatedAt()->format('P'),
        );
    }

    #[DataProvider('invalidSlugs')]
    public function testInvalidSlugIsRejected(
        string $slug,
    ): void {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new Organization($slug, 'Valid Name');
    }

    public static function invalidSlugs(): iterable
    {
        yield [''];
        yield ['ACME'];
        yield ['-acme'];
        yield ['acme-'];
        yield ['acme corp'];
        yield [str_repeat('a', 64)];
    }

    public function testBlankNameIsRejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new Organization('acme', '   ');
    }
}
