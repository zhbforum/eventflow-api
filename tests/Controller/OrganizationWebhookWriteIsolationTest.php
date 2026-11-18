<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use App\Entity\WebhookEndpoint;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class OrganizationWebhookWriteIsolationTest extends WebTestCase
{
    public function testWebhookWritesAreTenantScoped(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $suffix = bin2hex(random_bytes(8));
        $subject = 'webhook-write-' . $suffix;

        $organizationA = new Organization(
            'webhook-write-a-' . $suffix,
            'Webhook Write Organization A',
        );

        $organizationB = new Organization(
            'webhook-write-b-' . $suffix,
            'Webhook Write Organization B',
        );

        foreach (
            [
                $organizationA,
                $organizationB,
                new OrganizationMembership(
                    $organizationA,
                    $subject,
                    'admin',
                ),
                new OrganizationMembership(
                    $organizationB,
                    $subject,
                    'admin',
                ),
            ] as $entity
        ) {
            $em->persist($entity);
        }

        $em->flush();

        $organizationAId = $organizationA->getId();
        $organizationBId = $organizationB->getId();

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

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $csrfData = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($csrfData);

        $csrf = $csrfData['management_csrf_token'];

        self::assertIsString($csrf);

        $headersA = [
            'HTTP_X_ORGANIZATION_ID' => $organizationAId,
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ];

        $headersB = [
            'HTTP_X_ORGANIZATION_ID' => $organizationBId,
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ];

        $client->jsonRequest(
            'POST',
            '/api/v1/webhooks',
            [
                'url' => 'https://example.com/write-a-' . $suffix,
                'subscribedEvents' => ['order.created'],
            ],
            $headersA,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        $createdA = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($createdA);

        $idA = $createdA['id'];

        $client->jsonRequest(
            'POST',
            '/api/v1/webhooks',
            [
                'url' => 'https://example.com/write-b-' . $suffix,
                'subscribedEvents' => ['order.created'],
            ],
            $headersB,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_CREATED,
        );

        $createdB = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($createdB);

        $idB = $createdB['id'];

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        $endpointA = $em->find(WebhookEndpoint::class, $idA);
        $endpointB = $em->find(WebhookEndpoint::class, $idB);

        self::assertInstanceOf(WebhookEndpoint::class, $endpointA);
        self::assertInstanceOf(WebhookEndpoint::class, $endpointB);

        self::assertSame(
            $organizationAId,
            $endpointA->getOrganizationId(),
        );

        self::assertSame(
            $organizationBId,
            $endpointB->getOrganizationId(),
        );

        $originalUrlB = $endpointB->getUrl();
        $originalSecretB = $endpointB->getSigningSecret();

        $client->jsonRequest(
            'PATCH',
            '/api/v1/webhooks/' . $idB,
            ['url' => 'https://example.com/attacker'],
            $headersA,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );

        $client->request(
            'POST',
            '/api/v1/webhooks/' . $idB . '/rotate-secret',
            [],
            [],
            $headersA,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_NOT_FOUND,
        );

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        $endpointB = $em->find(WebhookEndpoint::class, $idB);

        self::assertInstanceOf(WebhookEndpoint::class, $endpointB);
        self::assertSame($originalUrlB, $endpointB->getUrl());
        self::assertSame(
            $originalSecretB,
            $endpointB->getSigningSecret(),
        );

        $client->jsonRequest(
            'PATCH',
            '/api/v1/webhooks/' . $idB,
            [
                'url' => 'https://example.com/updated-b-' . $suffix,
                'isActive' => false,
            ],
            $headersB,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $client->request(
            'POST',
            '/api/v1/webhooks/' . $idB . '/rotate-secret',
            [],
            [],
            $headersB,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $rotation = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($rotation);
        self::assertSame($idB, $rotation['id']);
        self::assertNotSame(
            $originalSecretB,
            $rotation['signingSecret'],
        );

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $em->clear();

        $endpointB = $em->find(WebhookEndpoint::class, $idB);

        self::assertInstanceOf(WebhookEndpoint::class, $endpointB);

        self::assertSame(
            'https://example.com/updated-b-' . $suffix,
            $endpointB->getUrl(),
        );

        self::assertFalse($endpointB->isActive());

        self::assertSame(
            $rotation['signingSecret'],
            $endpointB->getSigningSecret(),
        );
    }
}
