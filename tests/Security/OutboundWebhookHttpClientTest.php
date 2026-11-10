<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\MessageHandler\DeliverWebhookHandler;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class OutboundWebhookHttpClientTest extends KernelTestCase
{
    public function testPrivateAddressesAreBlocked(): void
    {
        $urls = [
            'https://127.0.0.1/hooks',
            'https://10.0.0.1/hooks',
            'https://192.168.1.10/hooks',
            'https://169.254.169.254/latest/meta-data/',
            'https://[::1]/hooks',
        ];

        foreach ($urls as $url) {
            $mock = new MockHttpClient(
                new MockResponse('', ['http_code' => 200]),
            );

            $client = new NoPrivateNetworkHttpClient($mock);

            try {
                $client->request('POST', $url);

                self::fail(
                    sprintf('Private address was allowed: %s', $url),
                );
            } catch (TransportExceptionInterface $exception) {
                self::assertStringContainsString(
                    'blocked',
                    $exception->getMessage(),
                );
            }

            self::assertSame(0, $mock->getRequestsCount());
        }
    }

    public function testHandlerUsesProtectedHttpClient(): void
    {
        self::bootKernel();

        $handler = static::getContainer()
            ->get(DeliverWebhookHandler::class);

        $property = (new ReflectionClass($handler))
            ->getProperty('httpClient');

        self::assertInstanceOf(
            NoPrivateNetworkHttpClient::class,
            $property->getValue($handler),
        );
    }
}
