<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\WebhookDelivery;
use App\Message\DeliverWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

#[AsMessageHandler]
final readonly class DeliverWebhookHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HttpClientInterface $httpClient,
    ) {}

    public function __invoke(DeliverWebhookMessage $message): void
    {
        $connection = $this->entityManager->getConnection();

        $connection->fetchOne(
            'SELECT pg_advisory_lock(
                hashtextextended(CAST(:delivery_id AS text), 0)
            )',
            ['delivery_id' => $message->deliveryId],
        );

        try {
            $this->deliver($message);
        } finally {
            $connection->fetchOne(
                'SELECT pg_advisory_unlock(
                    hashtextextended(CAST(:delivery_id AS text), 0)
                )',
                ['delivery_id' => $message->deliveryId],
            );
        }
    }

    private function deliver(DeliverWebhookMessage $message): void
    {
        $delivery = $this->entityManager
            ->getRepository(WebhookDelivery::class)
            ->find($message->deliveryId);

        if ($delivery === null) {
            throw new RuntimeException(
                'Webhook delivery not found',
            );
        }

        $this->entityManager->refresh($delivery);

        if (in_array(
            $delivery->getStatus(),
            ['delivered', 'failed'],
            true,
        )) {
            return;
        }

        $endpoint = $delivery->getEndpoint();

        if (!$endpoint->isActive()) {
            $delivery->markFailed(
                'Webhook endpoint is inactive',
            );

            $this->entityManager->flush();

            return;
        }

        $url = $endpoint->getUrl();

        if (
            parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
        ) {
            $delivery->markFailed('Invalid webhook URL');

            $this->entityManager->flush();

            return;
        }

        $delivery->startAttempt();
        $this->entityManager->flush();

        $event = $delivery->getEvent();

        try {
            $response = $this->httpClient->request(
                'POST',
                $url,
                [
                    'json' => [
                        'id' => $event->getId(),
                        'type' => $event->getType(),
                        'source' => $event->getSource(),
                        'payload' => $event->getPayload(),
                        'createdAt' => $event
                            ->getCreatedAt()
                            ->format(DATE_ATOM),
                    ],
                    'headers' => [
                        'X-EventFlow-Delivery-Id' =>
                        $delivery->getId(),
                    ],
                    'timeout' => 5,
                    'max_duration' => 10,
                    'max_redirects' => 0,
                    'extra' => [
                        'trace_content' => false,
                    ],
                ],
            );

            $status = $response->getStatusCode();

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(
                    sprintf(
                        'Webhook returned HTTP %d',
                        $status,
                    ),
                );
            }

            $delivery->markDelivered();

            $this->entityManager->flush();
        } catch (Throwable $exception) {
            $error = $exception instanceof TransportExceptionInterface
                ? 'HTTP transport error'
                : $exception->getMessage();

            $delivery->recordFailure($error);

            $this->entityManager->flush();

            throw $exception;
        }
    }
}
