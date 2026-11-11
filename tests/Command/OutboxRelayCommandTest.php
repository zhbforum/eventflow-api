<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\OutboxRelayCommand;
use App\Service\OutboxPublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class OutboxRelayCommandTest extends TestCase
{
    public function testRejectsInvalidBatchSize(): void
    {
        $tester = new CommandTester($this->createCommand());

        $exitCode = $tester->execute([
            '--batch-size' => '0',
        ]);

        self::assertSame(Command::INVALID, $exitCode);

        self::assertStringContainsString(
            'Invalid options',
            $tester->getDisplay(),
        );
    }

    public function testRejectsInvalidIdleDelay(): void
    {
        $tester = new CommandTester($this->createCommand());

        $exitCode = $tester->execute([
            '--idle-delay-ms' => '99',
        ]);

        self::assertSame(Command::INVALID, $exitCode);

        self::assertStringContainsString(
            'Invalid options',
            $tester->getDisplay(),
        );
    }

    public function testSubscribesToShutdownSignals(): void
    {
        $command = $this->createCommand();

        self::assertEqualsCanonicalizing(
            [SIGTERM, SIGINT],
            $command->getSubscribedSignals(),
        );

        self::assertFalse(
            $command->handleSignal(SIGTERM),
        );
    }

    public function testStopsAfterCurrentBatchWhenSignalArrives(): void
    {
        $entityManager = $this->createMock(
            EntityManagerInterface::class,
        );

        $sender = $this->createMock(
            SenderInterface::class,
        );

        $sender
            ->expects(self::never())
            ->method('send');

        $publisher = new OutboxPublisher(
            $entityManager,
            $sender,
        );

        $command = new OutboxRelayCommand($publisher);

        $entityManager
            ->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(
                static function (callable $operation) use ($command): ?bool {
                    self::assertFalse(
                        $command->handleSignal(SIGTERM),
                    );

                    return null;
                },
            );

        $entityManager
            ->expects(self::once())
            ->method('clear');

        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--batch-size' => '10',
            '--idle-delay-ms' => '100',
        ]);

        self::assertSame(
            Command::SUCCESS,
            $exitCode,
        );

        self::assertStringContainsString(
            'stopped gracefully',
            $tester->getDisplay(),
        );
    }

    private function createCommand(): OutboxRelayCommand
    {
        return new OutboxRelayCommand(
            new OutboxPublisher(
                $this->createStub(
                    EntityManagerInterface::class,
                ),
                $this->createStub(
                    SenderInterface::class,
                ),
            ),
        );
    }
}
