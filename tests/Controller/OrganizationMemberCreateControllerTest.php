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

final class OrganizationMemberCreateControllerTest extends WebTestCase
{
    public function testAdminAddsMemberAndDuplicateIsRejected(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(8));
        $owner = 'owner-' . $suffix;
        $target = 'new-member-' . $suffix;

        $organization = self::createOrganization(
            $suffix,
            $owner,
        );

        self::login($client, $owner, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $csrf = self::csrf($client);
        $url = self::url($organization);

        $headers = [
            'HTTP_X_ORGANIZATION_ID' => $organization->getId(),
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ];

        $payload = [
            'oidcSubject' => $target,
            'role' => 'viewer',
        ];

        $client->jsonRequest('POST', $url, $payload, $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        $data = self::decode($client);

        self::assertSame($target, $data['oidcSubject']);
        self::assertSame('viewer', $data['role']);
        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('createdAt', $data);

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $membership = $em
            ->getRepository(OrganizationMembership::class)
            ->findOneBy([
                'organization' => $organization->getId(),
                'oidcSubject' => $target,
            ]);

        self::assertInstanceOf(
            OrganizationMembership::class,
            $membership,
        );

        self::assertSame($data['id'], $membership->getId());

        $client->jsonRequest('POST', $url, $payload, $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_CONFLICT,
        );

        $client->request('GET', $url, [], [], $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $list = self::decode($client);

        self::assertSame(2, $list['pagination']['total']);
        self::assertContains(
            $target,
            array_column($list['items'], 'oidcSubject'),
        );
    }

    public function testRequiresAdminMembershipAndCsrf(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(8));
        $owner = 'admin-' . $suffix;
        $viewer = 'viewer-' . $suffix;

        $organization = self::createOrganization(
            $suffix,
            $owner,
        );

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->persist(new OrganizationMembership(
            $organization,
            $viewer,
            'viewer',
        ));
        $em->flush();

        $url = self::url($organization);

        $headers = [
            'HTTP_X_ORGANIZATION_ID' => $organization->getId(),
        ];

        $payload = [
            'oidcSubject' => 'target-' . $suffix,
            'role' => 'admin',
        ];

        $client->jsonRequest('POST', $url, $payload, $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        self::login($client, $owner, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_VIEWER',
        ]);

        $client->jsonRequest('POST', $url, $payload, $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        self::login($client, $viewer, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $client->jsonRequest('POST', $url, $payload, $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        self::login($client, $owner, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $client->jsonRequest('POST', $url, $payload, $headers);

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->jsonRequest(
            'POST',
            $url,
            $payload,
            [
                ...$headers,
                'HTTP_X_CSRF_TOKEN' => 'invalid',
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->jsonRequest(
            'POST',
            self::urlById(Organization::LEGACY_ID),
            $payload,
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

    public function testCannotModifyDifferentOrganization(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(8));
        $owner = 'owner-' . $suffix;

        $organizationA = self::createOrganization(
            'a-' . $suffix,
            $owner,
        );

        $organizationB = self::createOrganization(
            'b-' . $suffix,
            $owner,
        );

        self::login($client, $owner, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $csrf = self::csrf($client);

        $target = 'foreign-target-' . $suffix;

        $client->jsonRequest(
            'POST',
            self::url($organizationB),
            [
                'oidcSubject' => $target,
                'role' => 'admin',
            ],
            [
                'HTTP_X_ORGANIZATION_ID' =>
                $organizationA->getId(),
                'HTTP_X_CSRF_TOKEN' => $csrf,
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        self::assertNull(
            $em->getRepository(OrganizationMembership::class)
                ->findOneBy([
                    'organization' => $organizationB->getId(),
                    'oidcSubject' => $target,
                ]),
        );
    }

    public function testRejectsInvalidPayloads(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(8));
        $owner = 'owner-' . $suffix;

        $organization = self::createOrganization(
            $suffix,
            $owner,
        );

        self::login($client, $owner, [
            'ROLE_USER',
            'ROLE_EVENTFLOW_ADMIN',
        ]);

        $headers = [
            'HTTP_X_ORGANIZATION_ID' => $organization->getId(),
            'HTTP_X_CSRF_TOKEN' => self::csrf($client),
        ];

        $url = self::url($organization);

        foreach (
            [
                [
                    ['oidcSubject' => '', 'role' => 'viewer'],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                ],
                [
                    [
                        'oidcSubject' => ' user ',
                        'role' => 'admin',
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                ],
                [
                    [
                        'oidcSubject' => str_repeat('x', 256),
                        'role' => 'viewer',
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                ],
                [
                    [
                        'oidcSubject' => 'target',
                        'role' => 'superadmin',
                    ],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                ],
                [
                    [
                        'oidcSubject' => 'target',
                        'role' => 'viewer',
                        'organizationId' => 'foreign',
                    ],
                    Response::HTTP_BAD_REQUEST,
                ],
                [
                    ['oidcSubject' => 'target'],
                    Response::HTTP_BAD_REQUEST,
                ],
                [
                    [],
                    Response::HTTP_BAD_REQUEST,
                ],
            ] as [$payload, $expectedStatus]
        ) {
            $client->jsonRequest(
                'POST',
                $url,
                $payload,
                $headers,
            );

            self::assertResponseStatusCodeSame($expectedStatus);
        }

        $client->request(
            'POST',
            $url,
            [],
            [],
            [
                ...$headers,
                'CONTENT_TYPE' => 'application/json',
            ],
            '{"oidcSubject":',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_BAD_REQUEST,
        );
    }

    private static function createOrganization(
        string $suffix,
        string $subject,
    ): Organization {
        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $organization = new Organization(
            'member-create-' . $suffix,
            'Membership Test Organization',
        );

        $em->persist($organization);
        $em->persist(new OrganizationMembership(
            $organization,
            $subject,
            'admin',
        ));
        $em->flush();

        return $organization;
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

    private static function url(Organization $organization): string
    {
        return self::urlById($organization->getId());
    }

    private static function urlById(string $id): string
    {
        return '/api/v1/organizations/' . $id . '/members';
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
