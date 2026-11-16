<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WebhookSigner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookSignerTest extends TestCase
{
    private const DELIVERY_ID = '019aa000-0000-7000-8000-000000000001';
    private const TIMESTAMP = 1760000000;
    private const SECRET = 'test-signing-secret';
    private const BODY = '{"id":"evt-1","payload":{"name":"Мир"}}';

    public function testSignsExactRawBodyUsingVersionedHmacSha256(): void
    {
        $signature = (new WebhookSigner())->sign(
            self::BODY,
            self::DELIVERY_ID,
            self::TIMESTAMP,
            self::SECRET,
        );

        $expected = 'v1=' . hash_hmac(
            'sha256',
            'v1.' . self::TIMESTAMP . '.' . self::DELIVERY_ID . '.' . self::BODY,
            self::SECRET,
        );

        self::assertSame($expected, $signature);
        self::assertMatchesRegularExpression('/^v1=[a-f0-9]{64}$/D', $signature);
    }

    public function testChangingAnySignedInputChangesSignature(): void
    {
        $signer = new WebhookSigner();

        $original = $signer->sign(
            self::BODY,
            self::DELIVERY_ID,
            self::TIMESTAMP,
            self::SECRET,
        );

        $changedInputs = [
            [self::BODY . ' ', self::DELIVERY_ID, self::TIMESTAMP, self::SECRET],
            [self::BODY, '019aa000-0000-7000-8000-000000000002', self::TIMESTAMP, self::SECRET],
            [self::BODY, self::DELIVERY_ID, self::TIMESTAMP + 1, self::SECRET],
            [self::BODY, self::DELIVERY_ID, self::TIMESTAMP, 'another-secret'],
        ];

        foreach ($changedInputs as [$body, $id, $timestamp, $secret]) {
            self::assertNotSame(
                $original,
                $signer->sign($body, $id, $timestamp, $secret),
            );
        }
    }

    #[DataProvider('invalidSigningInputs')]
    public function testRejectsMissingSigningInputs(
        string $body,
        string $deliveryId,
        int $timestamp,
        string $secret,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        (new WebhookSigner())->sign($body, $deliveryId, $timestamp, $secret);
    }

    public static function invalidSigningInputs(): iterable
    {
        yield 'missing delivery ID' => [self::BODY, '', self::TIMESTAMP, self::SECRET];
        yield 'invalid timestamp' => [self::BODY, self::DELIVERY_ID, 0, self::SECRET];
        yield 'missing secret' => [self::BODY, self::DELIVERY_ID, self::TIMESTAMP, ''];
    }
}
