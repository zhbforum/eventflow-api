<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use JsonException;
use stdClass;

final class EventRequestFingerprint
{
    public static function keyHash(
        string $scope,
        string $key,
    ): string {
        return hash(
            'sha256',
            json_encode(
                [$scope, $key],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    public static function payloadFromJson(
        string $rawJson,
    ): array|stdClass {
        try {
            $body = json_decode(
                $rawJson,
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Invalid JSON request body.',
                0,
                $exception,
            );
        }

        if (
            !($body instanceof stdClass)
            || !property_exists($body, 'payload')
            || (
                !is_array($body->payload)
                && !($body->payload instanceof stdClass)
            )
        ) {
            throw new InvalidArgumentException(
                'Event payload must be a JSON object or array.',
            );
        }

        return $body->payload;
    }

    public static function fromJson(
        string $rawJson,
        string $type,
        string $source,
    ): string {
        $payload = self::payloadFromJson($rawJson);

        return hash(
            'sha256',
            json_encode(
                [
                    'type' => $type,
                    'source' => $source,
                    'payload' => self::canonicalize($payload),
                ],
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION,
            ),
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);

            ksort($properties, SORT_STRING);

            $normalized = new stdClass();

            foreach ($properties as $name => $nested) {
                $normalized->{(string) $name} =
                    self::canonicalize($nested);
            }

            return $normalized;
        }

        if (is_array($value)) {
            foreach ($value as $index => $nested) {
                $value[$index] = self::canonicalize($nested);
            }
        }

        return $value;
    }
}
