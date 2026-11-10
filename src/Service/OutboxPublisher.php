<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OutboxMessage;
use App\Message\DeliverWebhookMessage;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Throwable;

final readonly class OutboxPublisher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'messenger.transport.rabbitmq')]
        private SenderInterface $rabbitmqSender,
    ) {}

    /**
     * @return array{processed: int, published: int, failed: int}
     */
    public function publish(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException(
                'The limit must be between 1 and 1000.',
            );
        }

        $processed = 0;
        $published = 0;
        $failed = 0;
        $lastId = null;

        while ($processed < $limit) {
            $result = $this->entityManager->wrapInTransaction(
                function () use (&$lastId): ?bool {
                    $connection = $this->entityManager
                        ->getConnection();

                    $sql = <<<'SQL'
                        SELECT id
                        FROM outbox_messages
                        WHERE published_at IS NULL
                        SQL;

                    $parameters = [];

                    if ($lastId !== null) {
                        $sql .= ' AND id > :last_id';
                        $parameters['last_id'] = $lastId;
                    }

                    $sql .= <<<'SQL'

                        ORDER BY id ASC
                        LIMIT 1
                        FOR UPDATE SKIP LOCKED
                        SQL;

                    $id = $connection->fetchOne(
                        $sql,
                        $parameters,
                    );

                    if ($id === false) {
                        return null;
                    }

                    $lastId = (string) $id;

                    $outbox = $this->entityManager->find(
                        OutboxMessage::class,
                        $lastId,
                    );

                    if ($outbox === null) {
                        throw new LogicException(
                            'Locked outbox message was not found.',
                        );
                    }

                    $outbox->startPublishAttempt();

                    try {
                        $this->rabbitmqSender->send(
                            new Envelope(
                                new DeliverWebhookMessage(
                                    $outbox
                                        ->getDelivery()
                                        ->getId(),
                                ),
                            ),
                        );

                        $outbox->markPublished();

                        return true;
                    } catch (Throwable $exception) {
                        $outbox->recordFailure(
                            $exception->getMessage(),
                        );

                        return false;
                    }
                },
            );

            $this->entityManager->clear();

            if ($result === null) {
                break;
            }

            ++$processed;

            if ($result) {
                ++$published;
            } else {
                ++$failed;
            }
        }

        return [
            'processed' => $processed,
            'published' => $published,
            'failed' => $failed,
        ];
    }
}
