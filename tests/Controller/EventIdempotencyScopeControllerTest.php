<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Security\OidcUser;
use App\Tests\Fixtures\LegacyOrganizationTestContext;
use App\Service\EventRequestFingerprint;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class EventIdempotencyScopeControllerTest extends WebTestCase
{
    public function testSameKeyIsIsolatedBetweenOidcUsers(): void
    {
        $client = static::createClient();

        $key = 'oidc-scope-' . bin2hex(random_bytes(12));

        $body = [
            'type' => 'scope.test',
            'source' => 'scope-test-service',
            'payload' => [
                'requestId' => $key,
            ],
        ];

        $eventIds = [];

        $em = static::getContainer()->get(EntityManagerInterface::class);

        foreach (['scope-admin-a', 'scope-admin-b'] as $subject) {
            LegacyOrganizationTestContext::enable(
                $client,
                $em,
                $subject,
                'admin',
            );
        }

        foreach (
            [
                'scope-admin-a',
                'scope-admin-b',
                'scope-admin-a',
            ] as $index => $userId
        ) {
            $client->loginUser(
                new OidcUser(
                    $userId,
                    $userId,
                    null,
                    [
                        'ROLE_USER',
                        'ROLE_EVENTFLOW_ADMIN',
                    ],
                    time(),
                ),
                'main',
            );

            $client->request('GET', '/api/v1/auth/csrf');

            self::assertResponseStatusCodeSame(
                Response::HTTP_OK,
            );

            $csrfResponse = json_decode(
                (string) $client->getResponse()->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $csrf = $csrfResponse['management_csrf_token'];

            self::assertIsString($csrf);
            self::assertNotEmpty($csrf);

            $client->jsonRequest(
                'POST',
                '/api/v1/events',
                $body,
                [
                    'HTTP_IDEMPOTENCY_KEY' => $key,
                    'HTTP_X_CSRF_TOKEN' => $csrf,
                ],
            );

            self::assertResponseStatusCodeSame(
                Response::HTTP_CREATED,
            );

            $result = json_decode(
                (string) $client->getResponse()->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $eventIds[] = $result['id'];

            self::assertSame(
                $index === 2 ? 'true' : null,
                $client->getResponse()->headers->get(
                    'Idempotency-Replayed',
                ),
            );
        }

        self::assertSame(
            $eventIds[0],
            $eventIds[2],
        );

        self::assertNotSame(
            $eventIds[0],
            $eventIds[1],
        );

        $em = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        foreach (
            [
                'scope-admin-a' => $eventIds[0],
                'scope-admin-b' => $eventIds[1],
            ] as $userId => $expectedId
        ) {
            $hash = EventRequestFingerprint::keyHash(
                'oidc:' . $userId,
                $key,
            );

            $events = $em->getRepository(Event::class)
                ->findBy([
                    'idempotencyKeyHash' => $hash,
                ]);

            self::assertCount(1, $events);

            self::assertSame(
                $expectedId,
                $events[0]->getId(),
            );
        }
    }
}
