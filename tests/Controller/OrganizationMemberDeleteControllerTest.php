<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class OrganizationMemberDeleteControllerTest extends WebTestCase
{
    public function testAdminCanRemoveViewerAndAnotherAdmin(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(8));
        $subject = 'delete-owner-' . $suffix;

        $organization = self::organization($suffix, $subject);

        $viewer = self::member(
            $organization,
            'delete-viewer-' . $suffix,
            'viewer',
        );

        $otherAdmin = self::member(
            $organization,
            'delete-admin-' . $suffix,
            'admin',
        );

        self::login($client, $subject, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $headers = self::headers(
            $organization,
            self::csrf($client),
        );

        foreach ([$viewer, $otherAdmin] as $member) {
            $client->request(
                'DELETE',
                self::url($organization, $member->getId()),
                [],
                [],
                $headers,
            );

            self::assertResponseStatusCodeSame(
                Response::HTTP_NO_CONTENT,
            );

            self::assertSame(
                '',
                (string) $client->getResponse()->getContent(),
            );
        }

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        self::assertNull(
            $em->find(
                OrganizationMembership::class,
                $viewer->getId(),
            ),
        );

        self::assertNull(
            $em->find(
                OrganizationMembership::class,
                $otherAdmin->getId(),
            ),
        );

        $client->request(
            'GET',
            '/api/v1/organizations/'
                . $organization->getId()
                . '/members',
            [],
            [],
            $headers,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = self::decode($client);

        self::assertSame(1, $data['pagination']['total']);
        self::assertSame('admin', $data['items'][0]['role']);
        self::assertSame(
            $subject,
            $data['items'][0]['oidcSubject'],
        );
    }

    public function testCannotRemoveLastAdmin(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(8));
        $subject = 'last-admin-' . $suffix;

        $organization = self::organization($suffix, $subject);

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $membership = $em
            ->getRepository(OrganizationMembership::class)
            ->findOneBy([
                'organization' => $organization,
                'oidcSubject' => $subject,
            ]);

        self::assertInstanceOf(
            OrganizationMembership::class,
            $membership,
        );

        $membershipId = $membership->getId();

        self::login($client, $subject, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $client->request(
            'DELETE',
            self::url($organization, $membershipId),
            [],
            [],
            self::headers(
                $organization,
                self::csrf($client),
            ),
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CONFLICT,
        );

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        self::assertInstanceOf(
            OrganizationMembership::class,
            $em->find(
                OrganizationMembership::class,
                $membershipId,
            ),
        );
    }

    public function testRequiresAdminRoleAndValidCsrf(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(8));
        $owner = 'delete-owner-' . $suffix;

        $organization = self::organization($suffix, $owner);

        $viewer = self::member(
            $organization,
            'delete-viewer-' . $suffix,
            'viewer',
        );

        $url = self::url($organization, $viewer->getId());

        $headers = [
            'HTTP_X_ORGANIZATION_ID' => $organization->getId(),
        ];

        $client->request('DELETE', $url, [], [], $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        self::login($client, $owner, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_VIEWER',
        ]);

        $client->request('DELETE', $url, [], [], $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        self::login($client, $viewer->getOidcSubject(), [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $client->request('DELETE', $url, [], [], $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        self::login($client, $owner, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $client->request('DELETE', $url, [], [], $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request(
            'DELETE',
            $url,
            [],
            [],
            [
                ...$headers,
                'HTTP_X_CSRF_TOKEN' => 'invalid',
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request(
            'DELETE',
            '/api/v1/organizations/'
                . Organization::LEGACY_ID
                . '/members/'
                . $viewer->getId(),
            [],
            [],
            [
                'HTTP_X_ORGANIZATION_ID' =>
                Organization::LEGACY_ID,
                'HTTP_AUTHORIZATION' =>
                'Bearer eventflow-test-management-token',
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testCannotDeleteMemberOfOtherOrganization(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(8));
        $owner = 'delete-owner-' . $suffix;

        $organizationA = self::organization(
            'a-' . $suffix,
            $owner,
        );

        $organizationB = self::organization(
            'b-' . $suffix,
            $owner,
        );

        $foreignMember = self::member(
            $organizationB,
            'foreign-member-' . $suffix,
            'viewer',
        );

        self::login($client, $owner, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $headers = self::headers(
            $organizationA,
            self::csrf($client),
        );

        $client->request(
            'DELETE',
            self::url(
                $organizationB,
                $foreignMember->getId(),
            ),
            [],
            [],
            $headers,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );

        $client->request(
            'DELETE',
            self::url(
                $organizationA,
                $foreignMember->getId(),
            ),
            [],
            [],
            $headers,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        self::assertInstanceOf(
            OrganizationMembership::class,
            $em->find(
                OrganizationMembership::class,
                $foreignMember->getId(),
            ),
        );
    }

    public function testMissingAndInvalidMembershipIdsReturnNotFound(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(8));
        $owner = 'delete-owner-' . $suffix;

        $organization = self::organization($suffix, $owner);

        self::login($client, $owner, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $headers = self::headers(
            $organization,
            self::csrf($client),
        );

        foreach (
            [
                'not-a-uuid',
                Uuid::v7()->toRfc4122(),
            ] as $memberId
        ) {
            $client->request(
                'DELETE',
                self::url($organization, $memberId),
                [],
                [],
                $headers,
            );

            self::assertResponseStatusCodeSame(
                Response::HTTP_NOT_FOUND,
            );
        }
    }

    private static function organization(
        string $suffix,
        string $owner,
    ): Organization {
        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $organization = new Organization(
            'member-delete-' . $suffix,
            'Member Delete Test Organization',
        );

        $em->persist($organization);
        $em->persist(new OrganizationMembership(
            $organization,
            $owner,
            'admin',
        ));
        $em->flush();

        return $organization;
    }

    private static function member(
        Organization $organization,
        string $subject,
        string $role,
    ): OrganizationMembership {
        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $membership = new OrganizationMembership(
            $organization,
            $subject,
            $role,
        );

        $em->persist($membership);
        $em->flush();

        return $membership;
    }

    /**
     * @param list<string> $roles
     */
    private static function login(
        KernelBrowser $client,
        string $subject,
        array $roles,
    ): void {
        $client->loginUser(
            new OidcUser(
                $subject,
                $subject,
                null,
                $roles,
                time(),
            ),
            'main',
        );
    }

    private static function csrf(KernelBrowser $client): string
    {
        $client->request('GET', '/api/v1/auth/csrf');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = self::decode($client);

        $token = $data['management_csrf_token'] ?? null;

        self::assertIsString($token);

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private static function headers(
        Organization $organization,
        string $csrf,
    ): array {
        return [
            'HTTP_X_ORGANIZATION_ID' => $organization->getId(),
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ];
    }

    private static function url(
        Organization $organization,
        string $memberId,
    ): string {
        return '/api/v1/organizations/'
            . $organization->getId()
            . '/members/'
            . $memberId;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(KernelBrowser $client): array
    {
        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($data);

        return $data;
    }
}
