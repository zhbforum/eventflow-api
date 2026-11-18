<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\WebhookDelivery;
use App\Message\DeliverWebhookMessage;
use App\Service\WebhookSigner;
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
        private WebhookSigner $webhookSigner,
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
            throw new RuntimeException('Webhook delivery not found');
        }

        $this->entityManager->refresh($delivery);

        if (
            $message->replayGeneration
            !== $delivery->getReplayGeneration()
        ) {
            return;
        }

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
        $statusCode = null;

        try {
            $body = json_encode(
                [
                    'id' => $event->getId(),
                    'type' => $event->getType(),
                    'source' => $event->getSource(),
                    'payload' => $event->getPayload(),
                    'createdAt' => $event
                        ->getCreatedAt()
                        ->format(DATE_ATOM),
                ],
                JSON_THROW_ON_ERROR,
            );

            $timestamp = time();

            $signature = $this->webhookSigner->sign(
                $body,
                $delivery->getId(),
                $timestamp,
                $endpoint->getSigningSecret(),
            );

            $response = $this->httpClient->request(
                'POST',
                $url,
                [
                    'body' => $body,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-EventFlow-Delivery-Id' => $delivery->getId(),
                        'X-EventFlow-Timestamp' => (string) $timestamp,
                        'X-EventFlow-Signature' => $signature,
                    ],
                    'timeout' => 5,
                    'max_duration' => 10,
                    'max_redirects' => 0,
                    'extra' => [
                        'trace_content' => false,
                    ],
                ],
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new RuntimeException(
                    sprintf(
                        'Webhook returned HTTP %d',
                        $statusCode,
                    ),
                );
            }

            $delivery->markDelivered();

            $this->entityManager->flush();
        } catch (Throwable $exception) {
            $error = 'Webhook delivery failed';

            if (
                $statusCode !== null
                && ($statusCode < 200 || $statusCode >= 300)
            ) {
                $error = sprintf(
                    'Webhook returned HTTP %d',
                    $statusCode,
                );
            } elseif ($exception instanceof TransportExceptionInterface) {
                $error = 'HTTP transport error';
            }

            $delivery->recordFailure($error);

            $this->entityManager->flush();

            throw new RuntimeException($error);
        }
    }
}
