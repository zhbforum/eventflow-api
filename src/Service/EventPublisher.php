<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use Doctrine\ORM\EntityManagerInterface;

final readonly class EventPublisher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function publish(Event $event): void
    {
        $this->entityManager->wrapInTransaction(
            function () use ($event): void {
                $this->entityManager->persist($event);

                $endpoints = $this->entityManager
                    ->getRepository(WebhookEndpoint::class)
                    ->findBy(['isActive' => true]);

                foreach ($endpoints as $endpoint) {
                    if (!in_array(
                        $event->getType(),
                        $endpoint->getSubscribedEvents(),
                        true,
                    )) {
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
            },
        );
    }
}
