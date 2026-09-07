<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance;

use App\NickServ\Adapter\In\Maintenance\CleanupHistoryTask;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function sprintf;

#[CoversClass(CleanupHistoryTask::class)]
final class CleanupHistoryTaskTest extends TestCase
{
    private const string NOW = '2026-09-06 12:00:00 UTC';

    #[Test]
    public function getNameReturnsNickservCleanupHistory(): void
    {
        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $task = new CleanupHistoryTask($historyRepo, $logger, $this->clock(), 3600, 30);

        self::assertSame('nickserv.cleanup_history', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsConfiguredValue(): void
    {
        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $task = new CleanupHistoryTask($historyRepo, $logger, $this->clock(), 7200, 30);

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns250(): void
    {
        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $task = new CleanupHistoryTask($historyRepo, $logger, $this->clock(), 3600, 30);

        self::assertSame(250, $task->getOrder());
    }

    #[Test]
    public function runDoesNothingWhenRetentionDaysIsZero(): void
    {
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::never())->method('deleteOlderThan');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $task = new CleanupHistoryTask($historyRepo, $logger, $this->clock(), 3600, 0);
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenRetentionDaysIsNegative(): void
    {
        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::never())->method('deleteOlderThan');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $task = new CleanupHistoryTask($historyRepo, $logger, $this->clock(), 3600, -1);
        $task->run();
    }

    #[Test]
    public function runCallsDeleteOlderThanWithCorrectThreshold(): void
    {
        $retentionDays = 30;

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('deleteOlderThan')
            ->with(new DateTimeImmutable(self::NOW)->modify(sprintf('-%d days', $retentionDays)))
            ->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $task = new CleanupHistoryTask($historyRepo, $logger, $this->clock(), 3600, $retentionDays);
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

        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
        $historyRepo->method('deleteOlderThan')->willReturn(42);

        $task = new CleanupHistoryTask($historyRepo, $logger, $this->clock(), 3600, 30);
        $task->run();

        self::assertCount(1, $logMessages);
        self::assertStringContainsString('Cleaned up 42 history entries', $logMessages[0]);
        self::assertStringContainsString('30 days', $logMessages[0]);
    }

    #[Test]
    public function runDoesNotLogWhenNoEntriesDeleted(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
        $historyRepo->method('deleteOlderThan')->willReturn(0);

        $task = new CleanupHistoryTask($historyRepo, $logger, $this->clock(), 3600, 30);
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

        $historyRepo = $this->createStub(NickHistoryRepositoryInterface::class);
        $historyRepo->method('deleteOlderThan')->willReturn(100);

        $task = new CleanupHistoryTask($historyRepo, $logger, $this->clock(), 3600, 60);
        $task->run();

        self::assertCount(1, $logMessages);
        self::assertStringContainsString('100 history entries', $logMessages[0]);
        self::assertStringContainsString('60 days', $logMessages[0]);
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));

        return $clock;
    }
}
