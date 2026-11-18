<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class WebhookUrlSecurityTest extends WebTestCase
{
    private const AUTH = [
        'HTTP_AUTHORIZATION' =>
        'Bearer eventflow-test-management-token',
    ];

    public function testCreateRejectsUnsafeUrlComponents(): void
    {
        $client = static::createClient([], self::AUTH);

        foreach (
            [
                'https://user:password@example.com/hook',
                'https://user@example.com/hook',
                'https://example.com/hook#private',
            ] as $url
        ) {
            $client->jsonRequest(
                'POST',
                '/api/v1/webhooks',
                [
                    'url' => $url,
                    'subscribedEvents' => ['order.created'],
                ],
            );

            self::assertResponseStatusCodeSame(
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );

            $data = json_decode(
                (string) $client->getResponse()->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            self::assertSame(
                'url',
                $data['violations'][0]['propertyPath'],
            );
        }
    }

    public function testPatchRejectsUnsafeUrlComponents(): void
    {
        $client = static::createClient([], self::AUTH);

        $client->jsonRequest(
            'POST',
            '/api/v1/webhooks',
            [
                'url' => 'https://example.com/original',
                'subscribedEvents' => ['order.created'],
            ],
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

        $id = $created['id'];

        foreach (
            [
                'https://user:password@example.com/hook',
                'https://example.com/hook#fragment',
            ] as $url
        ) {
            $client->jsonRequest(
                'PATCH',
                '/api/v1/webhooks/' . $id,
                ['url' => $url],
            );

            self::assertResponseStatusCodeSame(
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $client->request(
            'GET',
            '/api/v1/webhooks/' . $id,
        );

        self::assertResponseStatusCodeSame(
            Response::HTTP_OK,
        );

        $current = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            'https://example.com/original',
            $current['url'],
        );
    }
}
