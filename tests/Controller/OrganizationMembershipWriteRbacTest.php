<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class OrganizationMembershipWriteRbacTest extends WebTestCase
{
    public function testWriteRequiresBothGlobalAndOrganizationAdmin(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));
        $subject = 'rbac-matrix-' . $suffix;

        $viewerOrganization = new Organization(
            'rbac-viewer-' . $suffix,
            'Viewer Organization',
        );

        $adminOrganization = new Organization(
            'rbac-admin-' . $suffix,
            'Admin Organization',
        );

        foreach (
            [
                $viewerOrganization,
                $adminOrganization,
                new OrganizationMembership(
                    $viewerOrganization,
                    $subject,
                    'viewer',
                ),
                new OrganizationMembership(
                    $adminOrganization,
                    $subject,
                    'admin',
                ),
            ] as $entity
        ) {
            $em->persist($entity);
        }

        $em->flush();

        $client->loginUser(
            new OidcUser(
                $subject,
                $subject,
                null,
                ['ROLE_USER', 'ROLE_EVENTFLOW_ADMIN'],
                time(),
            ),
            'main',
        );

        $client->request('GET', '/api/v1/auth/csrf');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $csrf = $data['management_csrf_token'];

        self::assertIsString($csrf);

        $body = [
            'type' => 'security.membership',
            'source' => 'rbac-matrix-' . $suffix,
            'payload' => ['test' => true],
        ];

        $viewerHeaders = [
            'HTTP_X_ORGANIZATION_ID' =>
            $viewerOrganization->getId(),
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ];

        $adminHeaders = [
            'HTTP_X_ORGANIZATION_ID' =>
            $adminOrganization->getId(),
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ];

        $client->jsonRequest(
            'POST',
            '/api/v1/events',
            $body,
            $viewerHeaders,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->jsonRequest(
            'POST',
            '/api/v1/events',
            $body,
            $adminHeaders,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        $created = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($created);

        $saved = $em->find(Event::class, $created['id']);

        self::assertInstanceOf(Event::class, $saved);

        self::assertSame(
            $adminOrganization->getId(),
            $saved->getOrganizationId(),
        );

        $client->loginUser(
            new OidcUser(
                $subject,
                $subject,
                null,
                ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
                time(),
            ),
            'main',
        );

        $client->jsonRequest(
            'POST',
            '/api/v1/events',
            $body,
            $adminHeaders,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request(
            'GET',
            '/api/v1/events/' . $created['id'],
            [],
            [],
            [
                'HTTP_X_ORGANIZATION_ID' =>
                $adminOrganization->getId(),
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $em->clear();

        self::assertCount(
            1,
            $em->getRepository(Event::class)->findBy([
                'source' => 'rbac-matrix-' . $suffix,
            ]),
        );
    }
}
