<?php

declare(strict_types=1);

namespace App\Tests\UI\CLI;

use App\Application\Port\UdbOfflineTakeoverInterface;
use App\UI\CLI\UdbTakeoverCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(UdbTakeoverCommand::class)]
final class UdbTakeoverCommandTest extends TestCase
{
    #[Test]
    public function dryRunValidatesWithoutApplying(): void
    {
        $takeover = $this->createMock(UdbOfflineTakeoverInterface::class);
        $takeover->expects(self::once())->method('takeover')->with('/udb', false)->willReturn(str_repeat('a', 64));

        $tester = new CommandTester(new UdbTakeoverCommand($takeover));

        self::assertSame(Command::SUCCESS, $tester->execute(['directory' => '/udb', '--dry-run' => true]));
        self::assertStringContainsString('validation succeeded', $tester->getDisplay());
    }

    #[Test]
    public function confirmationAppliesOnlyAfterApproval(): void
    {
        $takeover = $this->createMock(UdbOfflineTakeoverInterface::class);
        $takeover->expects(self::exactly(2))->method('takeover')
            ->willReturnCallback(static fn (string $directory, bool $apply): string => $apply ? str_repeat('b', 64) : str_repeat('a', 64));

        $tester = new CommandTester(new UdbTakeoverCommand($takeover));
        $tester->setInputs(['yes']);

        self::assertSame(Command::SUCCESS, $tester->execute(['directory' => '/udb']));
        self::assertStringContainsString('completed and approved', $tester->getDisplay());
    }

    #[Test]
    public function cancellationAndFailureAreReported(): void
    {
        $cancelled = $this->createMock(UdbOfflineTakeoverInterface::class);
        $cancelled->expects(self::once())->method('takeover')->with('/udb', false)->willReturn(str_repeat('a', 64));
        $cancelledTester = new CommandTester(new UdbTakeoverCommand($cancelled));
        $cancelledTester->setInputs(['no']);
        self::assertSame(Command::SUCCESS, $cancelledTester->execute(['directory' => '/udb']));
        self::assertStringContainsString('cancelled', $cancelledTester->getDisplay());

        $failing = $this->createStub(UdbOfflineTakeoverInterface::class);
        $failing->method('takeover')->willThrowException(new RuntimeException('invalid snapshot'));
        $failingTester = new CommandTester(new UdbTakeoverCommand($failing));
        self::assertSame(Command::FAILURE, $failingTester->execute(['directory' => '/udb', '--dry-run' => true]));
        self::assertStringContainsString('invalid snapshot', $failingTester->getDisplay());
    }
}
