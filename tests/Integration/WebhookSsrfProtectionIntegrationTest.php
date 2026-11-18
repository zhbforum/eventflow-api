<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\MessageHandler\DeliverWebhookHandler;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class WebhookSsrfProtectionIntegrationTest extends KernelTestCase
{
    public function testWorkerBlocksPrivateNetworkDestinations(): void
    {
        self::bootKernel();

        $handler = self::getContainer()->get(
            DeliverWebhookHandler::class,
        );

        self::assertInstanceOf(
            DeliverWebhookHandler::class,
            $handler,
        );

        $property = new ReflectionProperty(
            DeliverWebhookHandler::class,
            'httpClient',
        );

        $httpClient = $property->getValue($handler);

        self::assertInstanceOf(
            NoPrivateNetworkHttpClient::class,
            $httpClient,
        );

        $blockedUrls = [
            'https://127.0.0.1/hooks',
            'https://10.0.0.1/hooks',
            'https://172.16.0.1/hooks',
            'https://192.168.1.1/hooks',
            'https://169.254.169.254/latest/meta-data/',
            'https://[::1]/hooks',
        ];

        foreach ($blockedUrls as $url) {
            try {
                $response = $httpClient->request(
                    'POST',
                    $url,
                    [
                        'timeout' => 1,
                        'max_redirects' => 0,
                    ],
                );

                $response->getStatusCode();

                self::fail(
                    'Private network request was allowed: ' . $url,
                );
            } catch (TransportExceptionInterface $exception) {
                self::assertStringContainsString(
                    'blocked',
                    strtolower($exception->getMessage()),
                    $url,
                );
            }
        }
    }
}
