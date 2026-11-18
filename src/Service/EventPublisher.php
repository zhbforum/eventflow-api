<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\Organization;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Exception\IdempotencyConflictException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use stdClass;

final readonly class EventPublisher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function publish(Event $event): void
    {
        $this->entityManager->wrapInTransaction(
            function () use ($event): void {
                $this->persistEventWithOutbox($event);
            },
        );
    }

    public function publishIdempotent(
        string $type,
        string $source,
        array|stdClass $payload,
        string $idempotencyKey,
        string $rawJson,
        string $idempotencyScope,
        ?Organization $organization = null,
    ): EventPublicationResult {
        if (
            preg_match(
                '/\A[\x21-\x7E]{1,255}\z/D',
                $idempotencyKey,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Idempotency-Key must contain 1 to 255 '
                    . 'visible ASCII characters without spaces.',
            );
        }

        if ($idempotencyScope === '') {
            throw new InvalidArgumentException(
                'Idempotency scope cannot be empty.',
            );
        }

        $organizationId = $organization?->getId()
            ?? Organization::LEGACY_ID;

        $effectiveScope = $organizationId === Organization::LEGACY_ID
            ? $idempotencyScope
            : sprintf(
                'organization:%s:%s',
                $organizationId,
                $idempotencyScope,
            );

        $keyHash = EventRequestFingerprint::keyHash(
            $effectiveScope,
            $idempotencyKey,
        );

        $requestHash = EventRequestFingerprint::fromJson(
            $rawJson,
            $type,
            $source,
        );

        return $this->entityManager->wrapInTransaction(
            function () use (
                $type,
                $source,
                $payload,
                $keyHash,
                $requestHash,
                $organization,
                $organizationId,
            ): EventPublicationResult {
                $connection = $this->entityManager
                    ->getConnection();

                $connection->fetchOne(
                    'SELECT pg_advisory_xact_lock(
                        hashtextextended(
                            CAST(:lock_key AS text),
                            0
                        )
                    )',
                    [
                        'lock_key' => 'incoming-event:' . $keyHash,
                    ],
                );

                $existing = $this->entityManager
                    ->getRepository(Event::class)
                    ->findOneBy([
                        'idempotencyKeyHash' => $keyHash,
                    ]);

                if ($existing !== null) {
                    if (
                        $existing->getOrganizationId()
                        !== $organizationId
                    ) {
                        throw new LogicException(
                            'Idempotency scope organization mismatch.',
                        );
                    }

                    if (
                        $existing->getRequestHash()
                        !== $requestHash
                    ) {
                        throw new IdempotencyConflictException(
                            'Idempotency-Key was already used '
                                . 'with a different request.',
                        );
                    }

                    return new EventPublicationResult(
                        $existing,
                        true,
                    );
                }

                $event = new Event(
                    $type,
                    $source,
                    $payload,
                    $organization,
                );

                $event->assignIdempotency(
                    $keyHash,
                    $requestHash,
                );

                $this->persistEventWithOutbox($event);

                return new EventPublicationResult(
                    $event,
                    false,
                );
            },
        );
    }

    private function persistEventWithOutbox(
        Event $event,
    ): void {
        $this->entityManager->persist($event);

        $endpoints = $this->entityManager
            ->getRepository(WebhookEndpoint::class)
            ->findBy([
                'isActive' => true,
                'organization' => $event->getOrganizationId(),
            ]);

        foreach ($endpoints as $endpoint) {
            if (
                !in_array(
                    $event->getType(),
                    $endpoint->getSubscribedEvents(),
                    true,
                )
            ) {
                continue;
            }

            $delivery = new WebhookDelivery(
                $event,
                $endpoint,
            );

            $outboxMessage = new OutboxMessage(
                $delivery,
            );

            $this->entityManager->persist($delivery);
            $this->entityManager->persist($outboxMessage);
        }
    }
}
