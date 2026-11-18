<?php

declare(strict_types=1);

use App\Kernel;
use App\Security\OidcUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

require dirname(__DIR__) . '/bootstrap.php';

$prefix = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$worker = $argv[3] ?? '';

if (
    $prefix === ''
    || !in_array($mode, ['create', 'delete'], true)
    || !in_array($worker, ['a', 'b'], true)
) {
    fwrite(STDERR, "Invalid organization race probe arguments.\n");
    exit(2);
}

$fixture = json_decode(
    (string) file_get_contents($prefix),
    true,
    512,
    JSON_THROW_ON_ERROR,
);

$subject = $fixture['admin' . strtoupper($worker) . 'Subject'];
$targetId = $fixture['admin' . ($worker === 'a' ? 'B' : 'A') . 'Id'];

$kernel = new Kernel('test', true);

try {
    fwrite(STDERR, "[{$worker}] booting Symfony\n");
    $kernel->boot();

    $client = new KernelBrowser($kernel);
    $client->disableReboot();

    $client->loginUser(
        new OidcUser(
            $subject,
            $subject,
            null,
            ['ROLE_USER', 'ROLE_EVENTFLOW_ADMIN'],
            time(),
        ),
        'main',
    );

    $client->request('GET', '/api/v1/auth/csrf');

    if ($client->getResponse()->getStatusCode() !== 200) {
        throw new RuntimeException('Cannot issue CSRF token.');
    }

    $csrfData = json_decode(
        (string) $client->getResponse()->getContent(),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $csrf = $csrfData['management_csrf_token'] ?? null;

    if (!is_string($csrf) || $csrf === '') {
        throw new RuntimeException('CSRF token missing.');
    }

    $container = $kernel->getContainer()->get('test.service_container');
    $entityManager = $container->get(EntityManagerInterface::class);
    $connection = $entityManager->getConnection();

    $connection->fetchOne(
        "SELECT set_config('application_name', :name, false)",
        ['name' => $fixture['appPrefix'] . '-' . $worker],
    );

    fwrite(
        STDERR,
        sprintf(
            "[%s] ready on database %s, PID %s\n",
            $worker,
            $connection->fetchOne('SELECT current_database()'),
            $connection->fetchOne('SELECT pg_backend_pid()'),
        ),
    );

    file_put_contents($prefix . '.' . $worker . '.ready', '1');

    $deadline = microtime(true) + 90;

    while (!is_file($prefix . '.go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for .go.');
        }

        clearstatcache(true, $prefix . '.go');
        usleep(20_000);
    }

    $headers = [
        'HTTP_X_ORGANIZATION_ID' => $fixture['organizationId'],
        'HTTP_X_CSRF_TOKEN' => $csrf,
    ];

    $url = '/api/v1/organizations/' . $fixture['organizationId']
        . '/members';

    fwrite(STDERR, "[{$worker}] entering {$mode} request\n");

    if ($mode === 'create') {
        $client->jsonRequest(
            'POST',
            $url,
            [
                'oidcSubject' => $fixture['targetSubject'],
                'role' => 'viewer',
            ],
            $headers,
        );
    } else {
        $client->request(
            'DELETE',
            $url . '/' . $targetId,
            [],
            [],
            $headers,
        );
    }

    $result = [
        'status' => $client->getResponse()->getStatusCode(),
        'body' => (string) $client->getResponse()->getContent(),
    ];

    fwrite(STDERR, "[{$worker}] status {$result['status']}\n");

    file_put_contents(
        $prefix . '.' . $worker . '.result',
        json_encode($result, JSON_THROW_ON_ERROR),
    );
} catch (Throwable $exception) {
    fwrite(STDERR, (string) $exception . "\n");

    file_put_contents(
        $prefix . '.' . $worker . '.result',
        json_encode(
            [
                'status' => 0,
                'error' => $exception->getMessage(),
            ],
            JSON_THROW_ON_ERROR,
        ),
    );
} finally {
    $kernel->shutdown();
}
