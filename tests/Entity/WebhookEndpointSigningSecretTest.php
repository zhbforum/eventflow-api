<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\WebhookEndpoint;
use PHPUnit\Framework\TestCase;

final class WebhookEndpointSigningSecretTest extends TestCase
{
    public function testGeneratesSigningSecret(): void
    {
        $endpoint = new WebhookEndpoint(
            'https://example.com/hooks',
            ['order.created'],
        );

        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $endpoint->getSigningSecret(),
        );
    }

    public function testDifferentEndpointsHaveDifferentSecrets(): void
    {
        $first = new WebhookEndpoint(
            'https://example.com/first',
            ['order.created'],
        );

        $second = new WebhookEndpoint(
            'https://example.com/second',
            ['order.created'],
        );

        self::assertNotSame(
            $first->getSigningSecret(),
            $second->getSigningSecret(),
        );
    }

    public function testRotatesSigningSecret(): void
    {
        $endpoint = new WebhookEndpoint(
            'https://example.com/hooks',
            ['order.created'],
        );

        $original = $endpoint->getSigningSecret();

        $rotated = $endpoint->rotateSigningSecret();

        self::assertNotSame($original, $rotated);
        self::assertSame(
            $rotated,
            $endpoint->getSigningSecret(),
        );

        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $rotated,
        );
    }
}
