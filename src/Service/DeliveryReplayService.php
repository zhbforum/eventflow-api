<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookDeliveryReplay;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;

final readonly class DeliveryReplayService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function requestReplay(
        string $deliveryId,
        string $requestedBy,
    ): ?WebhookDeliveryReplay {
        if (
            trim($requestedBy) === ''
            || strlen($requestedBy) > 255
        ) {
            throw new InvalidArgumentException(
                'Invalid replay requester.',
            );
        }

        return $this->entityManager->wrapInTransaction(
            function () use (
                $deliveryId,
                $requestedBy,
            ): ?WebhookDeliveryReplay {
                $connection = $this->entityManager
                    ->getConnection();

                $connection->fetchOne(
                    'SELECT pg_advisory_xact_lock(
                        hashtextextended(
                            CAST(:delivery_id AS text),
                            0
                        )
                    )',
                    ['delivery_id' => $deliveryId],
                );

                $lockedId = $connection->fetchOne(
                    'SELECT id
                     FROM webhook_deliveries
                     WHERE id = :delivery_id
                     FOR UPDATE',
                    ['delivery_id' => $deliveryId],
                );

                if ($lockedId === false) {
                    return null;
                }

                $delivery = $this->entityManager->find(
                    WebhookDelivery::class,
                    (string) $lockedId,
                );

                if ($delivery === null) {
                    throw new LogicException(
                        'Locked webhook delivery not found.',
                    );
                }

                $this->entityManager->refresh($delivery);

                if ($delivery->getStatus() !== 'failed') {
                    throw new LogicException(
                        'Only failed deliveries can be replayed.',
                    );
                }

                if (!$delivery->getEndpoint()->isActive()) {
                    throw new LogicException(
                        'Inactive webhook endpoints cannot be replayed.',
                    );
                }

                $previousError = $delivery->getLastError();

                $delivery->requestReplay();

                $replay = new WebhookDeliveryReplay(
                    $delivery,
                    $requestedBy,
                    $previousError,
                );

                $outbox = new OutboxMessage($delivery);

                $this->entityManager->persist($replay);
                $this->entityManager->persist($outbox);

                return $replay;
            },
        );
    }
}
