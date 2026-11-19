<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PostgreSqlRateLimiter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:rate-limit:prune',
    description: 'Remove expired API rate-limit counters.',
)]
final class PruneRateLimitBucketsCommand extends Command
{
    public function __construct(
        private readonly PostgreSqlRateLimiter $limiter,
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
                'Maximum counters deleted per batch (1-1000).',
                '500',
            )
            ->addOption(
                'max-batches',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum batches processed per run (1-100).',
                '10',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $batchSize = filter_var(
            $input->getOption('batch-size'),
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 1000,
                ],
            ],
        );

        $maxBatches = filter_var(
            $input->getOption('max-batches'),
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 100,
                ],
            ],
        );

        if ($batchSize === false || $maxBatches === false) {
            $output->writeln(
                '<error>Invalid cleanup options.</error>',
            );

            return Command::INVALID;
        }

        $totalDeleted = 0;

        for ($batch = 0; $batch < $maxBatches; ++$batch) {
            $deleted = $this->limiter->purgeExpired(
                $batchSize,
            );

            $totalDeleted += $deleted;

            if ($deleted < $batchSize) {
                break;
            }
        }

        $output->writeln(sprintf(
            'Purged %d expired API rate-limit buckets.',
            $totalDeleted,
        ));

        return Command::SUCCESS;
    }
}
