<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Fixtures\TestWebhookReceiver;
use PHPUnit\Framework\TestCase;

final class TestWebhookReceiverTest extends TestCase
{
    private const NOW = 1760000000;
    private const SECRET = 'correct-test-secret';
    private const DELIVERY_ID = '019aa000-0000-7000-8000-000000000001';
    private const BODY = '{"type":"order.created","payload":{"id":42}}';

    public function testAcceptsValidSignature(): void
    {
        $receiver = new TestWebhookReceiver(self::SECRET);

        self::assertTrue($receiver->accepts(
            self::BODY,
            self::headers(),
            self::NOW,
        ));
    }

    public function testRejectsModifiedBody(): void
    {
        $receiver = new TestWebhookReceiver(self::SECRET);

        self::assertFalse($receiver->accepts(
            self::BODY . ' ',
            self::headers(),
            self::NOW,
        ));
    }

    public function testRejectsIncorrectSecret(): void
    {
        $receiver = new TestWebhookReceiver(self::SECRET);

        self::assertFalse($receiver->accepts(
            self::BODY,
            self::headers('wrong-secret'),
            self::NOW,
        ));
    }

    public function testRejectsExpiredAndFutureTimestamps(): void
    {
        $receiver = new TestWebhookReceiver(self::SECRET);

        foreach ([self::NOW - 301, self::NOW + 301] as $timestamp) {
            self::assertFalse($receiver->accepts(
                self::BODY,
                self::headers(self::SECRET, $timestamp),
                self::NOW,
            ));
        }
    }

    public function testRejectsReplayOfAcceptedDelivery(): void
    {
        $receiver = new TestWebhookReceiver(self::SECRET);
        $headers = self::headers();

        self::assertTrue($receiver->accepts(
            self::BODY,
            $headers,
            self::NOW,
        ));

        self::assertFalse($receiver->accepts(
            self::BODY,
            $headers,
            self::NOW,
        ));
    }

    public function testInvalidSignatureDoesNotConsumeDeliveryId(): void
    {
        $receiver = new TestWebhookReceiver(self::SECRET);
        $headers = self::headers();

        self::assertFalse($receiver->accepts(
            self::BODY . ' ',
            $headers,
            self::NOW,
        ));

        self::assertTrue($receiver->accepts(
            self::BODY,
            $headers,
            self::NOW,
        ));
    }

    public function testRejectsMissingDeliveryId(): void
    {
        $receiver = new TestWebhookReceiver(self::SECRET);
        $headers = self::headers();

        unset($headers['x-eventflow-delivery-id']);

        self::assertFalse($receiver->accepts(
            self::BODY,
            $headers,
            self::NOW,
        ));
    }

    public function testRejectsInvalidTimestamp(): void
    {
        $receiver = new TestWebhookReceiver(self::SECRET);
        $headers = self::headers();
        $headers['x-eventflow-timestamp'] = 'invalid';

        self::assertFalse($receiver->accepts(
            self::BODY,
            $headers,
            self::NOW,
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function headers(
        string $secret = self::SECRET,
        int $timestamp = self::NOW,
    ): array {
        $signedContent = sprintf(
            'v1.%d.%s.%s',
            $timestamp,
            self::DELIVERY_ID,
            self::BODY,
        );

        return [
            'content-type' => 'application/json',
            'x-eventflow-delivery-id' => self::DELIVERY_ID,
            'x-eventflow-timestamp' => (string) $timestamp,
            'x-eventflow-signature' => 'v1=' . hash_hmac(
                'sha256',
                $signedContent,
                $secret,
            ),
        ];
    }
}
