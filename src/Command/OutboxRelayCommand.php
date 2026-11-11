<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\OutboxPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:outbox:relay',
    description: 'Continuously publish pending outbox messages.',
)]
final class OutboxRelayCommand extends Command implements SignalableCommandInterface
{
    private bool $stopRequested = false;

    public function __construct(
        private readonly OutboxPublisher $publisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum messages per batch (1-100).',
                '10',
            )
            ->addOption(
                'idle-delay-ms',
                null,
                InputOption::VALUE_REQUIRED,
                'Idle delay in milliseconds (100-60000).',
                '1000',
            );
    }

    public function getSubscribedSignals(): array
    {
        return [SIGTERM, SIGINT];
    }

    public function handleSignal(
        int $signal,
        int|false $previousExitCode = 0,
    ): int|false {
        $this->stopRequested = true;

        return false;
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $batchSize = filter_var(
            $input->getOption('batch-size'),
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 100,
                ],
            ],
        );

        $idleDelayMs = filter_var(
            $input->getOption('idle-delay-ms'),
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 100,
                    'max_range' => 60000,
                ],
            ],
        );

        if ($batchSize === false || $idleDelayMs === false) {
            $io->error(
                'Invalid options: batch-size must be 1-100 '
                . 'and idle-delay-ms must be 100-60000.',
            );

            return Command::INVALID;
        }

        $this->stopRequested = false;
        $failureDelayMs = $idleDelayMs;

        $io->writeln('Outbox relay started.');

        while (!$this->stopRequested) {
            $result = $this->publisher->publish($batchSize);

            if ($result['processed'] > 0) {
                $io->writeln(
                    sprintf(
                        'Processed: %d | Published: %d | Failed: %d',
                        $result['processed'],
                        $result['published'],
                        $result['failed'],
                    ),
                    OutputInterface::VERBOSITY_VERBOSE,
                );
            }

            if ($this->stopRequested) {
                break;
            }

            if ($result['failed'] > 0) {
                $failureDelayMs = min(
                    30000,
                    max($idleDelayMs, $failureDelayMs * 2),
                );

                usleep($failureDelayMs * 1000);

                continue;
            }

            $failureDelayMs = $idleDelayMs;

            if ($result['processed'] === 0) {
                usleep($idleDelayMs * 1000);
            }
        }

        $io->success('Outbox relay stopped gracefully.');

        return Command::SUCCESS;
    }
}
