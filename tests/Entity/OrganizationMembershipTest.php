<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OrganizationMembershipTest extends TestCase
{
    public function testAdminMembershipCreation(): void
    {
        $organization = new Organization('acme', 'Acme');

        $membership = new OrganizationMembership(
            $organization,
            'oidc-user-123',
            'admin',
        );

        self::assertTrue(Uuid::isValid($membership->getId()));
        self::assertSame(
            $organization,
            $membership->getOrganization(),
        );
        self::assertSame(
            'oidc-user-123',
            $membership->getOidcSubject(),
        );
        self::assertSame('admin', $membership->getRole());
        self::assertTrue($membership->isAdmin());
        self::assertSame(
            '+00:00',
            $membership->getCreatedAt()->format('P'),
        );
    }

    public function testViewerMembership(): void
    {
        $membership = new OrganizationMembership(
            new Organization('acme', 'Acme'),
            'oidc-user-456',
            'viewer',
        );

        self::assertSame('viewer', $membership->getRole());
        self::assertFalse($membership->isAdmin());
    }

    #[DataProvider('invalidSubjects')]
    public function testInvalidSubjectIsRejected(
        string $subject,
    ): void {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new OrganizationMembership(
            new Organization('acme', 'Acme'),
            $subject,
            'viewer',
        );
    }

    public static function invalidSubjects(): iterable
    {
        yield [''];
        yield [' '];
        yield [' user'];
        yield ['user '];
        yield [str_repeat('a', 256)];
    }

    #[DataProvider('invalidRoles')]
    public function testInvalidRoleIsRejected(
        string $role,
    ): void {
        $this->expectException(
            InvalidArgumentException::class,
        );

        new OrganizationMembership(
            new Organization('acme', 'Acme'),
            'oidc-user-123',
            $role,
        );
    }

    public static function invalidRoles(): iterable
    {
        yield [''];
        yield ['owner'];
        yield ['ADMIN'];
    }
}
