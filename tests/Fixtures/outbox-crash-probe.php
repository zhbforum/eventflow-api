<?php

declare(strict_types=1);

use App\Entity\Event;
use App\Entity\OutboxMessage;
use App\Entity\WebhookDelivery;
use App\Entity\WebhookEndpoint;
use App\Message\DeliverWebhookMessage;
use App\Service\OutboxPublisher;
use App\Tests\Fixtures\OutboxCrashDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

require dirname(__DIR__) . '/bootstrap.php';

$mode = $argv[1] ?? '';
$stateFile = $argv[2] ?? '';

if (
    !in_array($mode, ['crash', 'recover'], true)
    || $stateFile === ''
) {
    fwrite(
        STDERR,
        "Usage: php outbox-crash-probe.php crash|recover STATE_FILE\n",
    );

    exit(2);
}

fwrite(STDERR, "[probe] Started in {$mode} mode\n");

$entityManager = OutboxCrashDatabase::entityManager();
$connection = $entityManager->getConnection();

fwrite(STDERR, "[probe] Connected to test database\n");

try {
    if ($mode === 'crash') {
        $event = new Event(
            'order.created',
            'outbox-crash-recovery-test',
            ['probe' => true],
        );

        $endpoint = new WebhookEndpoint(
            'https://example.com/outbox-crash-test',
            ['order.created'],
        );

        $delivery = new WebhookDelivery(
            $event,
            $endpoint,
        );

        $outbox = new OutboxMessage($delivery);

        $state = [
            'event_id' => $event->getId(),
            'endpoint_id' => $endpoint->getId(),
            'delivery_id' => $delivery->getId(),
            'outbox_id' => $outbox->getId(),
        ];

        file_put_contents(
            $stateFile,
            json_encode(
                $state,
                JSON_THROW_ON_ERROR,
            ),
        );

        foreach (
            [$event, $endpoint, $delivery, $outbox] as $entity
        ) {
            $entityManager->persist($entity);
        }

        $entityManager->flush();

        fwrite(
            STDERR,
            "[probe] Outbox record committed\n",
        );

        $sender = new class(
            $entityManager,
            $stateFile,
        ) implements SenderInterface {
            public function __construct(
                private EntityManagerInterface $entityManager,
                private string $stateFile,
            ) {}

            // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by SenderInterface.
            public function send(Envelope $envelope): Envelope
            {
                $this->entityManager->flush();

                fwrite(
                    STDERR,
                    "[probe] Inside uncommitted transaction\n",
                );

                file_put_contents(
                    $this->stateFile . '.entered',
                    '1',
                );

                while (true) {
                    usleep(100_000);
                }
            }
        };

        (new OutboxPublisher(
            $entityManager,
            $sender,
        ))->publish(1);

        throw new RuntimeException(
            'Crash probe unexpectedly finished.',
        );
    }

    $state = json_decode(
        (string) file_get_contents($stateFile),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $sender = new class implements SenderInterface {
        /** @var list<string> */
        public array $deliveryIds = [];

        public function send(Envelope $envelope): Envelope
        {
            $message = $envelope->getMessage();

            if (!$message instanceof DeliverWebhookMessage) {
                throw new RuntimeException(
                    'Unexpected message type.',
                );
            }

            $this->deliveryIds[] = $message->deliveryId;

            return $envelope;
        }
    };

    $result = (new OutboxPublisher(
        $entityManager,
        $sender,
    ))->publish(1);

    file_put_contents(
        $stateFile . '.result',
        json_encode(
            [
                'result' => $result,
                'delivery_ids' => $sender->deliveryIds,
                'expected_delivery_id' => $state['delivery_id'],
            ],
            JSON_THROW_ON_ERROR,
        ),
    );

    fwrite(STDERR, "[probe] Recovery finished\n");
} finally {
    $entityManager->close();
    $connection->close();
}
