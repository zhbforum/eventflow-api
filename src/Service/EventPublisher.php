<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Message\DeliverWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class EventPublisher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {}

    public function publish(Event $event): void
    {
        $this->entityManager->wrapInTransaction(
            function () use ($event): void {
                $this->entityManager->persist($event);

                $endpoints = $this->entityManager
                    ->getRepository(WebhookEndpoint::class)
                    ->findBy(['isActive' => true]);

                $deliveries = [];

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

                    $this->entityManager->persist($delivery);
                    $deliveries[] = $delivery;
                }

                $this->entityManager->flush();

                foreach ($deliveries as $delivery) {
                    $this->messageBus->dispatch(
                        new DeliverWebhookMessage(
                            $delivery->getId(),
                        ),
                    );
                }
            },
        );
    }
}
