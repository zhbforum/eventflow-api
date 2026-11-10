<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\OutboxPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:outbox:publish',
    description: 'Publish pending outbox messages to RabbitMQ.',
)]
final class PublishOutboxCommand extends Command
{
    public function __construct(
        private readonly OutboxPublisher $publisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum number of messages to process.',
            '100',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $limit = filter_var(
            $input->getOption('limit'),
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 1000,
                ],
            ],
        );

        if ($limit === false) {
            $io->error(
                'The --limit option must be an integer between 1 and 1000.',
            );

            return Command::INVALID;
        }

        $result = $this->publisher->publish($limit);

        $io->writeln(sprintf(
            'Processed: %d | Published: %d | Failed: %d',
            $result['processed'],
            $result['published'],
            $result['failed'],
        ));

        if ($result['failed'] > 0) {
            $io->warning(
                'Some messages could not be published. '
                    . 'They remain in the outbox for retry.',
            );

            return Command::FAILURE;
        }

        $io->success('Outbox processing completed.');

        return Command::SUCCESS;
    }
}
