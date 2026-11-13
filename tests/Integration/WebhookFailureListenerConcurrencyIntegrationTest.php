<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\EventListener\WebhookDeliveryFailedListener;
use App\Message\DeliverWebhookMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class WebhookFailureListenerConcurrencyIntegrationTest extends KernelTestCase
{
    public function testListenerDoesNotOverwriteAlreadyDeliveredState(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $delivery = self::createDelivery($entityManager);
        $deliveryId = $delivery->getId();

        $entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE webhook_deliveries
                SET
                    status = 'delivered',
                    delivered_at = NOW() AT TIME ZONE 'UTC',
                    last_error = NULL
                WHERE id = :delivery_id
                SQL,
            ['delivery_id' => $deliveryId],
        );

        self::assertSame(
            'pending',
            $delivery->getStatus(),
        );

        $listener = new WebhookDeliveryFailedListener(
            $entityManager,
        );

        $listener(self::failureEvent($deliveryId));

        self::assertSame(
            'delivered',
            $delivery->getStatus(),
        );

        self::assertNotNull(
            $delivery->getDeliveredAt(),
        );

        self::assertNull(
            $delivery->getLastError(),
        );

        self::assertSame(
            'delivered',
            $entityManager->getConnection()->fetchOne(
                'SELECT status
                 FROM webhook_deliveries
                 WHERE id = :delivery_id',
                ['delivery_id' => $deliveryId],
            ),
        );
    }

    public function testListenerHoldsAdvisoryLockDuringFailureFlush(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );

        $delivery = self::createDelivery($entityManager);
        $deliveryId = $delivery->getId();

        $secondConnection = DriverManager::getConnection(
            $entityManager->getConnection()->getParams(),
        );

        $probe = new class($secondConnection, $deliveryId) {
            public ?bool $lockAvailableDuringFlush = null;

            public function __construct(
                private Connection $connection,
                private string $deliveryId,
            ) {}

            public function onFlush(): void
            {
                $available = (int) $this->connection->fetchOne(
                    <<<'SQL'
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
                        SQL,
                    ['delivery_id' => $this->deliveryId],
                );

                $this->lockAvailableDuringFlush = $available === 1;

                if ($available === 1) {
                    $this->connection->fetchOne(
                        <<<'SQL'
                            SELECT pg_advisory_unlock(
                                hashtextextended(
                                    CAST(:delivery_id AS text),
                                    0
                                )
                            )
                            SQL,
                        ['delivery_id' => $this->deliveryId],
                    );
                }
            }
        };

        $eventManager = $entityManager->getEventManager();

        $eventManager->addEventListener(
            [Events::onFlush],
            $probe,
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

            $listener = new WebhookDeliveryFailedListener(
                $entityManager,
            );

            $listener(self::failureEvent($deliveryId));

            self::assertFalse(
                $probe->lockAvailableDuringFlush,
            );

            self::assertSame(
                'failed',
                $delivery->getStatus(),
            );

            self::assertSame(
                'Webhook returned HTTP 503',
                $delivery->getLastError(),
            );

            $availableAfter = (int) $secondConnection->fetchOne(
                <<<'SQL'
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
                    SQL,
                ['delivery_id' => $deliveryId],
            );

            self::assertSame(1, $availableAfter);

            if ($availableAfter === 1) {
                $secondConnection->fetchOne(
                    <<<'SQL'
                        SELECT pg_advisory_unlock(
                            hashtextextended(
                                CAST(:delivery_id AS text),
                                0
                            )
                        )
                        SQL,
                    ['delivery_id' => $deliveryId],
                );
            }
        } finally {
            $eventManager->removeEventListener(
                [Events::onFlush],
                $probe,
            );

            $secondConnection->close();
        }
    }

    private static function failureEvent(
        string $deliveryId,
    ): WorkerMessageFailedEvent {
        return new WorkerMessageFailedEvent(
            new Envelope(
                new DeliverWebhookMessage($deliveryId),
            ),
            'rabbitmq',
            new RuntimeException('HTTP 503'),
        );
    }

    private static function createDelivery(
        EntityManagerInterface $entityManager,
    ): WebhookDelivery {
        $event = new Event(
            'order.created',
            'failure-listener-concurrency-test',
            ['orderId' => 'ORD-LOCK-FAILURE-001'],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/failure-lock-test',
            ['order.created'],
        );

        $delivery = new WebhookDelivery(
            $event,
            $endpoint,
        );

        $delivery->recordFailure(
            'Webhook returned HTTP 503',
        );

        $entityManager->persist($event);
        $entityManager->persist($endpoint);
        $entityManager->persist($delivery);
        $entityManager->flush();

        return $delivery;
    }
}
