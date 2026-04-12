<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Maintenance;

use App\Application\ChanServ\Maintenance\CleanupChannelHistoryTask;
use App\Domain\ChanServ\Repository\ChannelHistoryRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function sprintf;

#[CoversClass(CleanupChannelHistoryTask::class)]
final class CleanupChannelHistoryTaskTest extends TestCase
{
    #[Test]
    public function getNameReturnsChanservCleanupChannelHistory(): void
    {
        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $task = new CleanupChannelHistoryTask($historyRepo, $logger, 3600, 30);

        self::assertSame('chanserv.cleanup_channel_history', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsConfiguredValue(): void
    {
        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $task = new CleanupChannelHistoryTask($historyRepo, $logger, 7200, 30);

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns260(): void
    {
        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $task = new CleanupChannelHistoryTask($historyRepo, $logger, 3600, 30);

        self::assertSame(260, $task->getOrder());
    }

    #[Test]
    public function runDoesNothingWhenRetentionDaysIsZero(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::never())->method('deleteOlderThan');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $task = new CleanupChannelHistoryTask($historyRepo, $logger, 3600, 0);
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenRetentionDaysIsNegative(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::never())->method('deleteOlderThan');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $task = new CleanupChannelHistoryTask($historyRepo, $logger, 3600, -1);
        $task->run();
    }

    #[Test]
    public function runCallsDeleteOlderThanWithCorrectThreshold(): void
    {
        $retentionDays = 30;

        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(static function (DateTimeImmutable $threshold) use ($retentionDays): bool {
                $expected = (new DateTimeImmutable())->modify(sprintf('-%d days', $retentionDays));

                return $threshold->format('Y-m-d') === $expected->format('Y-m-d');
            }))
            ->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $task = new CleanupChannelHistoryTask($historyRepo, $logger, 3600, $retentionDays);
        $task->run();
    }

    #[Test]
    public function runLogsWhenEntriesAreDeleted(): void
    {
        $logMessages = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $msg) use (&$logMessages): void {
            $logMessages[] = $msg;
        });

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('deleteOlderThan')->willReturn(42);

        $task = new CleanupChannelHistoryTask($historyRepo, $logger, 3600, 30);
        $task->run();

        self::assertCount(1, $logMessages);
        self::assertStringContainsString('Cleaned up 42 channel history entries', $logMessages[0]);
        self::assertStringContainsString('30 days', $logMessages[0]);
    }

    #[Test]
    public function runDoesNotLogWhenNoEntriesDeleted(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('deleteOlderThan')->willReturn(0);

        $task = new CleanupChannelHistoryTask($historyRepo, $logger, 3600, 30);
        $task->run();
    }

    #[Test]
    public function runLogsCorrectNumberOfDeletedEntries(): void
    {
        $logMessages = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $msg) use (&$logMessages): void {
            $logMessages[] = $msg;
        });

        $historyRepo = $this->createStub(ChannelHistoryRepositoryInterface::class);
        $historyRepo->method('deleteOlderThan')->willReturn(100);

        $task = new CleanupChannelHistoryTask($historyRepo, $logger, 3600, 60);
        $task->run();

        self::assertCount(1, $logMessages);
        self::assertStringContainsString('100 channel history entries', $logMessages[0]);
        self::assertStringContainsString('60 days', $logMessages[0]);
    }
}
