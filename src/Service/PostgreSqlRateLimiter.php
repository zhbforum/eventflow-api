<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use RuntimeException;

final readonly class PostgreSqlRateLimiter
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function consume(
        string $bucketKey,
        int $limit,
        int $windowSeconds = 60,
    ): RateLimitDecision {
        if (
            preg_match('/\A[a-f0-9]{64}\z/D', $bucketKey) !== 1
            || $limit < 1
            || $limit > 10000
            || $windowSeconds < 1
            || $windowSeconds > 86400
        ) {
            throw new InvalidArgumentException(
                'Invalid rate limit configuration.',
            );
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO api_rate_limit_buckets AS bucket (
                    bucket_key,
                    hit_count,
                    reset_at
                )
                VALUES (
                    :bucket_key,
                    1,
                    clock_timestamp()
                        + (:window_seconds * INTERVAL '1 second')
                )
                ON CONFLICT (bucket_key) DO UPDATE SET
                    hit_count = CASE
                        WHEN bucket.reset_at <= clock_timestamp()
                            THEN 1
                        ELSE LEAST(
                            bucket.hit_count + 1,
                            :limit_plus_one
                        )
                    END,
                    reset_at = CASE
                        WHEN bucket.reset_at <= clock_timestamp()
                            THEN clock_timestamp()
                                + (:window_seconds * INTERVAL '1 second')
                        ELSE bucket.reset_at
                    END
                RETURNING
                    hit_count,
                    GREATEST(
                        1,
                        CEIL(
                            EXTRACT(
                                EPOCH FROM (
                                    reset_at - clock_timestamp()
                                )
                            )
                        )::integer
                    ) AS retry_after
                SQL,
            [
                'bucket_key' => $bucketKey,
                'window_seconds' => $windowSeconds,
                'limit_plus_one' => $limit + 1,
            ],
            [
                'bucket_key' => ParameterType::STRING,
                'window_seconds' => ParameterType::INTEGER,
                'limit_plus_one' => ParameterType::INTEGER,
            ],
        );

        if ($row === false) {
            throw new RuntimeException(
                'Rate limit counter update failed.',
            );
        }

        $count = (int) $row['hit_count'];
        $allowed = $count <= $limit;

        return new RateLimitDecision(
            $allowed,
            max(0, $limit - $count),
            $allowed
                ? null
                : max(1, (int) $row['retry_after']),
        );
    }

    public function purgeExpired(int $batchSize = 100): int
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new InvalidArgumentException(
                'Invalid rate limit cleanup batch size.',
            );
        }

        return $this->connection->executeStatement(
            <<<'SQL'
                WITH expired AS (
                    SELECT bucket_key
                    FROM api_rate_limit_buckets
                    WHERE reset_at < clock_timestamp()
                        - INTERVAL '10 minutes'
                    ORDER BY reset_at
                    LIMIT :batch_size
                    FOR UPDATE SKIP LOCKED
                )
                DELETE FROM api_rate_limit_buckets AS bucket
                USING expired
                WHERE bucket.bucket_key = expired.bucket_key
                    AND bucket.reset_at < clock_timestamp()
                        - INTERVAL '10 minutes'
                SQL,
            ['batch_size' => $batchSize],
            ['batch_size' => ParameterType::INTEGER],
        );
    }
}
