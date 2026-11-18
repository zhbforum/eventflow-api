<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
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
        string $organizationId = Organization::LEGACY_ID,
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
                $organizationId,
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
                    'SELECT d.id
                     FROM webhook_deliveries d
                     INNER JOIN events e
                         ON e.id = d.event_id
                     INNER JOIN webhook_endpoints w
                         ON w.id = d.endpoint_id
                     WHERE d.id = :delivery_id
                       AND e.organization_id = :organization_id
                       AND w.organization_id = :organization_id
                     FOR UPDATE OF d',
                    [
                        'delivery_id' => $deliveryId,
                        'organization_id' => $organizationId,
                    ],
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

                if (
                    $delivery->getEvent()->getOrganizationId()
                    !== $organizationId
                    || $delivery->getEndpoint()->getOrganizationId()
                    !== $organizationId
                ) {
                    return null;
                }

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
