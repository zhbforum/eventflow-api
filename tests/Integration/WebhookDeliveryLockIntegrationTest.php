<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Message\DeliverWebhookMessage;
use App\MessageHandler\DeliverWebhookHandler;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebhookDeliveryLockIntegrationTest extends KernelTestCase
{
    public function testDeliveryLockIsHeldDuringHttpRequest(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $event = new Event(
            'order.created',
            'lock-test',
            ['orderId' => 'ORD-LOCK-001'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/lock-test',
            ['order.created'],
        );

        $delivery = new WebhookDelivery(
            $event,
            $endpoint,
        );

        $entityManager->persist($event);
        $entityManager->persist($endpoint);
        $entityManager->persist($delivery);
        $entityManager->flush();

        $deliveryId = $delivery->getId();

        $secondConnection = DriverManager::getConnection(
            $entityManager->getConnection()->getParams(),
        );

        $lockSql = <<<'SQL'
            SELECT CASE
                WHEN pg_try_advisory_lock(
                    hashtextextended(
                        CAST(:delivery_id AS text),
                        0
                    )
                )
                THEN 1
                ELSE 0
            END
            SQL;

        $unlockSql = <<<'SQL'
            SELECT CASE
                WHEN pg_advisory_unlock(
                    hashtextextended(
                        CAST(:delivery_id AS text),
                        0
                    )
                )
                THEN 1
                ELSE 0
            END
            SQL;

        $parameters = [
            'delivery_id' => $deliveryId,
        ];

        $requestCount = 0;

        $httpClient = new MockHttpClient(
            static function (
                string $method,
                string $url,
            ) use (
                &$requestCount,
                $secondConnection,
                $lockSql,
                $parameters,
            ): MockResponse {
                ++$requestCount;

                self::assertSame('POST', $method);

                self::assertSame(
                    'https://example.com/lock-test',
                    $url,
                );

                $acquired = (int) $secondConnection->fetchOne(
                    $lockSql,
                    $parameters,
                );

                self::assertSame(0, $acquired);

                return new MockResponse('', [
                    'http_code' => 204,
                ]);
            },
        );

        $handler = new DeliverWebhookHandler(
            $entityManager,
            $httpClient,
        );

        $message = new DeliverWebhookMessage(
            $deliveryId,
        );

        try {
            $firstPid = (int) $entityManager
                ->getConnection()
                ->fetchOne('SELECT pg_backend_pid()');

            $secondPid = (int) $secondConnection
                ->fetchOne('SELECT pg_backend_pid()');

            self::assertNotSame(
                $firstPid,
                $secondPid,
            );

            $handler($message);

            self::assertSame(1, $requestCount);

            $acquiredAfterDelivery = (int) $secondConnection
                ->fetchOne(
                    $lockSql,
                    $parameters,
                );

            self::assertSame(
                1,
                $acquiredAfterDelivery,
            );

            $unlocked = (int) $secondConnection
                ->fetchOne(
                    $unlockSql,
                    $parameters,
                );

            self::assertSame(1, $unlocked);

            $handler($message);

            self::assertSame(1, $requestCount);

            $entityManager->refresh($delivery);

            self::assertSame(
                'delivered',
                $delivery->getStatus(),
            );

            self::assertSame(
                1,
                $delivery->getAttempts(),
            );

            self::assertNotNull(
                $delivery->getDeliveredAt(),
            );
        } finally {
            $secondConnection->close();
        }
    }
}
