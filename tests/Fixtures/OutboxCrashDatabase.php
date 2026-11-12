<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use RuntimeException;

final class OutboxCrashDatabase
{
    public static function connect(): Connection
    {
        if (getenv('APP_ENV') !== 'test') {
            throw new RuntimeException(
                'APP_ENV=test is required.',
            );
        }

        $url = getenv('DATABASE_URL');

        if (!is_string($url) || $url === '') {
            throw new RuntimeException(
                'DATABASE_URL is missing.',
            );
        }

        $parts = parse_url($url);

        if (
            $parts === false
            || !isset($parts['host'], $parts['path'])
        ) {
            throw new RuntimeException(
                'Invalid DATABASE_URL.',
            );
        }

        $baseName = rawurldecode(
            trim($parts['path'], '/'),
        );

        $token = getenv('TEST_TOKEN') ?: '';

        if (
            !preg_match('/^[a-zA-Z0-9_]+$/', $baseName)
            || !preg_match('/^[a-zA-Z0-9_]*$/', $token)
        ) {
            throw new RuntimeException(
                'Invalid test database name.',
            );
        }

        $testName = $baseName . '_test' . $token;

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $parts['host'],
            'port' => $parts['port'] ?? 5432,
            'user' => rawurldecode(
                $parts['user'] ?? '',
            ),
            'password' => rawurldecode(
                $parts['pass'] ?? '',
            ),
            'dbname' => $testName,
        ]);

        if (
            $connection->fetchOne(
                'SELECT current_database()',
            ) !== $testName
        ) {
            $connection->close();

            throw new RuntimeException(
                'Wrong test database.',
            );
        }

        return $connection;
    }

    public static function entityManager(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfig(
            [dirname(__DIR__, 2) . '/src/Entity'],
            true,
        );

        $config->enableNativeLazyObjects(true);

        $config->setNamingStrategy(
            new UnderscoreNamingStrategy(),
        );

        return new EntityManager(
            self::connect(),
            $config,
        );
    }
}
