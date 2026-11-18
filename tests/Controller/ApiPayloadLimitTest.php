<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ApiPayloadLimitTest extends WebTestCase
{
    public function testOversizedOrganizationRequestReturns413(): void
    {
        $client = static::createClient();

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

        self::assertJsonStringEqualsJsonString(
            '{"error":"Payload too large"}',
            (string) $client->getResponse()->getContent(),
        );

        self::assertTrue(
            $client->getResponse()
                ->headers
                ->hasCacheControlDirective('no-store'),
        );
    }

    public function testOversizedEventRequestReturns413(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/events',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            str_repeat('x', 1_048_577),
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        );
    }

    public function testMismatchedContentLengthCannotBypassLimit(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/organizations',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => '1',
            ],
            str_repeat('x', 65_537),
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        );
    }

    public function testDeclaredOversizedLengthIsRejected(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/organizations',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => '65537',
            ],
            '{}',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
        );
    }

    public function testBodyWithinLimitReachesExistingSecurityGuard(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/organizations',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            str_repeat('x', 65_536),
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }

    public function testPublicHealthEndpointIsUnaffected(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/health');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }
}
