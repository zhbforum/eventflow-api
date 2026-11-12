<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final readonly class WorkerSignalProbeMessage
{
    public function __construct(
        public int $id,
    ) {}
}

$stateFile = $argv[1] ?? null;

if ($stateFile === null) {
    fwrite(STDERR, "Missing state file.\n");
    exit(1);
}

$record = static function (string $event) use ($stateFile): void {
    file_put_contents(
        $stateFile,
        $event . "\n",
        FILE_APPEND | LOCK_EX,
    );
};

$transport = new InMemoryTransport();

$transport->send(
    new Envelope(new WorkerSignalProbeMessage(1)),
);

$transport->send(
    new Envelope(new WorkerSignalProbeMessage(2)),
);

$handler = static function (
    WorkerSignalProbeMessage $message,
) use ($record): void {
    $record('started:' . $message->id);

    if ($message->id === 1) {
        usleep(2_000_000);
    }

    $record('finished:' . $message->id);
};

$bus = new MessageBus([
    new HandleMessageMiddleware(
        new HandlersLocator([
            WorkerSignalProbeMessage::class => [$handler],
        ]),
    ),
]);

$routableBus = new RoutableMessageBus(
    new ServiceLocator([]),
    $bus,
);

$receivers = new ServiceLocator([
    'probe' => static fn() => $transport,
]);

$command = new ConsumeMessagesCommand(
    $routableBus,
    $receivers,
    new EventDispatcher(),
    receiverNames: ['probe'],
    signals: [SIGTERM, SIGINT],
);

$application = new Application();
$application->setAutoExit(false);
$application->addCommand($command);

$exitCode = $application->run(
    new ArrayInput([
        'command' => 'messenger:consume',
        'receivers' => ['probe'],
        '--time-limit' => '15',
        '--sleep' => '1',
    ]),
    new NullOutput(),
);

$record('acknowledged:' . count(
    $transport->getAcknowledged(),
));

$record('remaining:' . count(
    iterator_to_array($transport->get(), false),
));

$record('exit:' . $exitCode);

exit($exitCode);
