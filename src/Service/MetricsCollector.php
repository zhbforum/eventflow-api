<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final readonly class MetricsCollector
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function render(): string
    {
        $deliveries = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COUNT(*) FILTER (
                        WHERE status = 'pending'
                    ) AS pending,

                    COUNT(*) FILTER (
                        WHERE status = 'processing'
                    ) AS processing,

                    COUNT(*) FILTER (
                        WHERE status = 'delivered'
                    ) AS delivered,

                    COUNT(*) FILTER (
                        WHERE status = 'failed'
                    ) AS failed,

                    COUNT(*) FILTER (
                        WHERE status = 'pending'
                          AND attempts > 0
                    ) AS retrying,

                    COALESCE(
                        EXTRACT(EPOCH FROM (
                            (NOW() AT TIME ZONE 'UTC')
                            - (
                                MIN(created_at) FILTER (
                                    WHERE status = 'pending'
                                )
                            )
                        )),
                        0
                    ) AS oldest_pending_age_seconds

                FROM webhook_deliveries
                SQL,
        );

        $outbox = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COUNT(*) FILTER (
                        WHERE published_at IS NULL
                    ) AS pending,

                    COUNT(*) FILTER (
                        WHERE published_at IS NOT NULL
                    ) AS published,

                    COUNT(*) FILTER (
                        WHERE published_at IS NULL
                          AND last_error IS NOT NULL
                    ) AS publish_failures,

                    COUNT(*) FILTER (
                        WHERE published_at IS NULL
                          AND created_at <=
                              (NOW() AT TIME ZONE 'UTC')
                              - INTERVAL '5 minutes'
                    ) AS older_than_5m,

                    COUNT(*) FILTER (
                        WHERE published_at IS NULL
                          AND created_at <=
                              (NOW() AT TIME ZONE 'UTC')
                              - INTERVAL '15 minutes'
                    ) AS older_than_15m,

                    COALESCE(
                        EXTRACT(EPOCH FROM (
                            (NOW() AT TIME ZONE 'UTC')
                            - (
                                MIN(created_at) FILTER (
                                    WHERE published_at IS NULL
                                )
                            )
                        )),
                        0
                    ) AS oldest_pending_age_seconds

                FROM outbox_messages
                SQL,
        );

        $endpoints = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COUNT(*) AS total,

                    COUNT(*) FILTER (
                        WHERE is_active = TRUE
                    ) AS active

                FROM webhook_endpoints
                SQL,
        );

        $lines = [
            '# HELP eventflow_webhook_deliveries Number of webhook deliveries by status.',
            '# TYPE eventflow_webhook_deliveries gauge',

            sprintf(
                'eventflow_webhook_deliveries{status="pending"} %d',
                (int) ($deliveries['pending'] ?? 0),
            ),

            sprintf(
                'eventflow_webhook_deliveries{status="processing"} %d',
                (int) ($deliveries['processing'] ?? 0),
            ),

            sprintf(
                'eventflow_webhook_deliveries{status="delivered"} %d',
                (int) ($deliveries['delivered'] ?? 0),
            ),

            sprintf(
                'eventflow_webhook_deliveries{status="failed"} %d',
                (int) ($deliveries['failed'] ?? 0),
            ),

            '# HELP eventflow_webhook_deliveries_retrying Number of pending webhook deliveries with previous attempts.',
            '# TYPE eventflow_webhook_deliveries_retrying gauge',

            sprintf(
                'eventflow_webhook_deliveries_retrying %d',
                (int) ($deliveries['retrying'] ?? 0),
            ),

            '# HELP eventflow_webhook_oldest_pending_age_seconds Age of the oldest pending webhook delivery in seconds.',
            '# TYPE eventflow_webhook_oldest_pending_age_seconds gauge',

            sprintf(
                'eventflow_webhook_oldest_pending_age_seconds %s',
                self::formatSeconds(
                    $deliveries['oldest_pending_age_seconds'] ?? 0,
                ),
            ),

            '# HELP eventflow_outbox_messages Number of outbox messages by publication state.',
            '# TYPE eventflow_outbox_messages gauge',

            sprintf(
                'eventflow_outbox_messages{state="pending"} %d',
                (int) ($outbox['pending'] ?? 0),
            ),

            sprintf(
                'eventflow_outbox_messages{state="published"} %d',
                (int) ($outbox['published'] ?? 0),
            ),

            '# HELP eventflow_outbox_publish_failures Number of unpublished outbox messages with a recorded publication error.',
            '# TYPE eventflow_outbox_publish_failures gauge',

            sprintf(
                'eventflow_outbox_publish_failures %d',
                (int) ($outbox['publish_failures'] ?? 0),
            ),

            '# HELP eventflow_outbox_oldest_pending_age_seconds Age of the oldest pending outbox message in seconds.',
            '# TYPE eventflow_outbox_oldest_pending_age_seconds gauge',

            sprintf(
                'eventflow_outbox_oldest_pending_age_seconds %s',
                self::formatSeconds(
                    $outbox['oldest_pending_age_seconds'] ?? 0,
                ),
            ),

            '# HELP eventflow_outbox_stale_messages Number of unpublished outbox messages older than the specified age.',
            '# TYPE eventflow_outbox_stale_messages gauge',

            sprintf(
                'eventflow_outbox_stale_messages{age="5m"} %d',
                (int) ($outbox['older_than_5m'] ?? 0),
            ),

            sprintf(
                'eventflow_outbox_stale_messages{age="15m"} %d',
                (int) ($outbox['older_than_15m'] ?? 0),
            ),

            '# HELP eventflow_webhook_endpoints_active Number of active webhook endpoints.',
            '# TYPE eventflow_webhook_endpoints_active gauge',

            sprintf(
                'eventflow_webhook_endpoints_active %d',
                (int) ($endpoints['active'] ?? 0),
            ),

            '# HELP eventflow_webhook_endpoints_total Total number of webhook endpoints.',
            '# TYPE eventflow_webhook_endpoints_total gauge',

            sprintf(
                'eventflow_webhook_endpoints_total %d',
                (int) ($endpoints['total'] ?? 0),
            ),
        ];

        return implode("\n", $lines) . "\n";
    }

    private static function formatSeconds(mixed $value): string
    {
        return number_format(
            max(0.0, (float) $value),
            3,
            '.',
            '',
        );
    }
}
