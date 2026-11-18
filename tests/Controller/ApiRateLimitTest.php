<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiRateLimitTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('API_RATE_LIMIT_ENABLED') !== '1') {
            self::markTestSkipped(
                'Requires API_RATE_LIMIT_ENABLED=1.',
            );
        }
    }

    public function testReadLimitAndUserIsolation(): void
    {
        $client = static::createClient();

        $subject = 'reader-' . bin2hex(random_bytes(12));

        self::login(
            $client,
            $subject,
            ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
        );

        $client->request(
            'GET',
            '/api/v1/organizations',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        self::setBucketCount('read', $subject, 119);

        $client->request(
            'GET',
            '/api/v1/organizations',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $client->request(
            'GET',
            '/api/v1/organizations',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_TOO_MANY_REQUESTS,
        );

        $response = $client->getResponse();

        self::assertSame(
            '120',
            $response->headers->get('X-RateLimit-Limit'),
        );

        self::assertSame(
            '0',
            $response->headers->get('X-RateLimit-Remaining'),
        );

        self::assertTrue(
            $response->headers->hasCacheControlDirective('no-store'),
        );

        $retryAfter = $response->headers->get('Retry-After');

        self::assertNotNull($retryAfter);

        self::assertGreaterThanOrEqual(
            1,
            (int) $retryAfter,
        );

        self::assertLessThanOrEqual(
            60,
            (int) $retryAfter,
        );

        self::assertJsonStringEqualsJsonString(
            '{"error":"Rate limit exceeded"}',
            (string) $response->getContent(),
        );

        self::login(
            $client,
            'another-reader-' . bin2hex(random_bytes(12)),
            ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
        );

        $client->request(
            'GET',
            '/api/v1/organizations',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );
    }

    public function testWriteLimitAndPayloadPriority(): void
    {
        $client = static::createClient();

        $subject = 'writer-' . bin2hex(random_bytes(12));

        self::login(
            $client,
            $subject,
            ['ROLE_USER', 'ROLE_EVENTFLOW_ADMIN'],
        );

        $payload = [
            'slug' => 'rate-test',
            'name' => 'Rate Test',
        ];

        $client->jsonRequest(
            'POST',
            '/api/v1/organizations',
            $payload,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        self::setBucketCount('write', $subject, 29);

        $client->jsonRequest(
            'POST',
            '/api/v1/organizations',
            $payload,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->jsonRequest(
            'POST',
            '/api/v1/organizations',
            $payload,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_TOO_MANY_REQUESTS,
        );

        self::assertNotNull(
            $client->getResponse()->headers->get('Retry-After'),
        );

        $client->request(
            'POST',
            '/api/v1/organizations',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            str_repeat('x', 65_537),
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        );
    }

    public function testDeniedOidcRequestsStillConsumeQuota(): void
    {
        $client = static::createClient();
        $subject = 'denied-' . bin2hex(random_bytes(12));

        self::login(
            $client,
            $subject,
            ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
        );

        $client->request('GET', '/api/v1/events');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        self::setBucketCount('read', $subject, 119);

        $client->request('GET', '/api/v1/events');

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request('GET', '/api/v1/events');

        self::assertResponseStatusCodeSame(
            Response::HTTP_TOO_MANY_REQUESTS,
        );

        self::assertSame(
            '120',
            $client->getResponse()->headers->get(
                'X-RateLimit-Limit',
            ),
        );
    }

    public function testAuthenticatedAuthEndpointsShareReadQuota(): void
    {
        $client = static::createClient();
        $subject = 'auth-read-' . bin2hex(random_bytes(12));

        self::login(
            $client,
            $subject,
            ['ROLE_USER', 'ROLE_EVENTFLOW_VIEWER'],
        );

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        self::setBucketCount('read', $subject, 119);

        $client->request('GET', '/api/v1/auth/csrf');

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $client->request('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(
            Response::HTTP_TOO_MANY_REQUESTS,
        );
    }

    private static function setBucketCount(
        string $category,
        string $subject,
        int $count,
    ): void {
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $bucketKey = hash(
            'sha256',
            'eventflow:v1:' . $category . ':oidc:' . $subject,
        );

        $updated = $entityManager
            ->getConnection()
            ->executeStatement(
                <<<'SQL'
                    UPDATE api_rate_limit_buckets
                    SET
                        hit_count = :count,
                        reset_at = clock_timestamp()
                            + INTERVAL '60 seconds'
                    WHERE bucket_key = :bucket_key
                    SQL,
                [
                    'count' => $count,
                    'bucket_key' => $bucketKey,
                ],
            );

        self::assertSame(
            1,
            $updated,
            'Expected exactly one existing rate limit bucket.',
        );
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
}
