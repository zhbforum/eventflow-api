<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ManagementApiAccessTest extends WebTestCase
{
    public function testLegacyReadRoutesRequireToken(): void
    {
        $client = static::createClient();

        foreach (
            [
                '/api/v1/events/00000000-0000-7000-8000-000000000000',
                '/api/v1/webhooks/00000000-0000-7000-8000-000000000000',
            ] as $path
        ) {
            $client->request('GET', $path);

            self::assertResponseStatusCodeSame(
                Response::HTTP_FORBIDDEN,
            );
        }
    }

    public function testWebhookCreationRequiresTokenBeforeValidation(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/webhooks',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{invalid-json',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );

        $client->request(
            'POST',
            '/api/v1/webhooks',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer wrong-token',
            ],
            '{"url":"https://example.com/hook"}',
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_FORBIDDEN,
        );
    }
}
