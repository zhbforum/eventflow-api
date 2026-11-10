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
        if ($event->getReceiverName() !== 'async') {
            return;
        }

        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();

        if (!$message instanceof DeliverWebhookMessage) {
            return;
        }

        $delivery = $this->entityManager
            ->getRepository(WebhookDelivery::class)
            ->find($message->deliveryId);

        if ($delivery === null) {
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
    }
}
