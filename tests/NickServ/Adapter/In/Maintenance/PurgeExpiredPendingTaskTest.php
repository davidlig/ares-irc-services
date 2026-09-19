<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance;

use App\NickServ\Adapter\In\Maintenance\PurgeExpiredPendingTask;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(PurgeExpiredPendingTask::class)]
final class PurgeExpiredPendingTaskTest extends TestCase
{
    #[Test]
    public function getNameReturnsTaskName(): void
    {
        $task = new PurgeExpiredPendingTask(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->clock(),
            3600,
        );

        self::assertSame('nickserv.purge_expired_pending', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsAndGetOrderReturnInjectedValues(): void
    {
        $task = new PurgeExpiredPendingTask(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->clock(),
            7200,
        );

        self::assertSame(7200, $task->getIntervalSeconds());
        self::assertSame(100, $task->getOrder());
    }

    #[Test]
    public function runCallsDeleteExpiredPendingAndLogsWhenDeletedGreaterThanZero(): void
    {
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('deleteExpiredPending')->with($this->now())->willReturn(3);

        $logMessages = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $msg) use (&$logMessages): void {
            $logMessages[] = $msg;
        });

        $task = new PurgeExpiredPendingTask($repo, $logger, $this->clock(), 3600);
        $task->run();

        self::assertCount(1, $logMessages);
        self::assertStringContainsString('purged 3 expired pending', $logMessages[0]);
    }

    #[Test]
    public function runDoesNotLogWhenDeletedIsZero(): void
    {
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('deleteExpiredPending')->with($this->now())->willReturn(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $task = new PurgeExpiredPendingTask($repo, $logger, $this->clock(), 3600);
        $task->run();
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now());

        return $clock;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }
}
