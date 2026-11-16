<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\WebhookDelivery;
use App\Message\DeliverWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

#[AsEventListener(
    event: WorkerMessageFailedEvent::class,
    priority: 0,
)]
final readonly class WebhookDeliveryFailedListener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if (!in_array(
            $event->getReceiverName(),
            ['async', 'rabbitmq'],
            true,
        )) {
            return;
        }

        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();

        if (!$message instanceof DeliverWebhookMessage) {
            return;
        }

        $connection = $this->entityManager->getConnection();

        $connection->fetchOne(
            'SELECT pg_advisory_lock(
                hashtextextended(CAST(:delivery_id AS text), 0)
            )',
            ['delivery_id' => $message->deliveryId],
        );

        try {
            $delivery = $this->entityManager
                ->getRepository(WebhookDelivery::class)
                ->find($message->deliveryId);

            if ($delivery === null) {
                return;
            }

            $this->entityManager->refresh($delivery);

            if (
                $message->replayGeneration
                !== $delivery->getReplayGeneration()
            ) {
                // Stale failures must not affect a newer replay.
                return;
            }

            if (in_array(
                $delivery->getStatus(),
                ['delivered', 'failed'],
                true,
            )) {
                return;
            }

            $error = $delivery->getLastError()
                ?? 'Webhook delivery failed: '
                . $event->getThrowable()->getMessage();

            $delivery->markFailed($error);

            $this->entityManager->flush();
        } finally {
            $connection->fetchOne(
                'SELECT pg_advisory_unlock(
                    hashtextextended(CAST(:delivery_id AS text), 0)
                )',
                ['delivery_id' => $message->deliveryId],
            );
        }
    }
}
