<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiAnonymousRateLimitTest extends WebTestCase
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

    public function testForwardedForCannotBypassAnonymousLimit(): void
    {
        $client = static::createClient();

        $address = '198.51.100.' . random_int(1, 254);

        $client->request(
            'GET',
            '/api/v1/organizations',
            [],
            [],
            [
                'REMOTE_ADDR' => $address,
                'HTTP_X_FORWARDED_FOR' => '192.0.2.1',
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $bucketKey = hash(
            'sha256',
            'eventflow:v1:anon:general:' . $address,
        );

        $connection = static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getConnection();

        $updated = $connection->executeStatement(
            <<<'SQL'
                UPDATE api_rate_limit_buckets
                SET
                    hit_count = 299,
                    reset_at = clock_timestamp()
                        + INTERVAL '60 seconds'
                WHERE bucket_key = :key
                SQL,
            ['key' => $bucketKey],
        );

        self::assertSame(1, $updated);

        $client->request(
            'GET',
            '/api/v1/organizations',
            [],
            [],
            [
                'REMOTE_ADDR' => $address,
                'HTTP_X_FORWARDED_FOR' => '192.0.2.2',
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request(
            'GET',
            '/api/v1/organizations',
            [],
            [],
            [
                'REMOTE_ADDR' => $address,
                'HTTP_X_FORWARDED_FOR' => '192.0.2.3',
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_TOO_MANY_REQUESTS,
        );

        self::assertSame(
            '300',
            $client->getResponse()->headers->get(
                'X-RateLimit-Limit',
            ),
        );

        self::assertNotNull(
            $client->getResponse()->headers->get(
                'Retry-After',
            ),
        );

        $client->request(
            'GET',
            '/api/v1/organizations',
            [],
            [],
            [
                'REMOTE_ADDR' => '203.0.113.5',
                'HTTP_X_FORWARDED_FOR' => '192.0.2.3',
            ],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testLoginHasSeparateRateLimit(): void
    {
        $client = static::createClient();

        $address = '198.51.100.' . random_int(1, 254);

        $connection = static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getConnection();

        $bucketKey = hash(
            'sha256',
            'eventflow:v1:anon:login:' . $address,
        );

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO api_rate_limit_buckets (
                    bucket_key,
                    hit_count,
                    reset_at
                )
                VALUES (
                    :key,
                    20,
                    clock_timestamp() + INTERVAL '60 seconds'
                )
                ON CONFLICT (bucket_key) DO UPDATE
                SET
                    hit_count = 20,
                    reset_at = clock_timestamp()
                        + INTERVAL '60 seconds'
                SQL,
            ['key' => $bucketKey],
        );

        $client->request(
            'GET',
            '/api/v1/auth/login',
            [],
            [],
            ['REMOTE_ADDR' => $address],
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_TOO_MANY_REQUESTS,
        );

        self::assertSame(
            '20',
            $client->getResponse()->headers->get(
                'X-RateLimit-Limit',
            ),
        );

        self::assertNotNull(
            $client->getResponse()->headers->get(
                'Retry-After',
            ),
        );
    }
}
