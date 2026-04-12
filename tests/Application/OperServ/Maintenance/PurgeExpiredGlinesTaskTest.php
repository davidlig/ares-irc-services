<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Maintenance;

use App\Application\OperServ\Maintenance\PurgeExpiredGlinesTask;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PurgeExpiredGlinesTask::class)]
final class PurgeExpiredGlinesTaskTest extends TestCase
{
    private const string SERVER_NAME = 'test-server.example.com';

    #[Test]
    public function getNameReturnsOperservPurgeExpiredGlines(): void
    {
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new PurgeExpiredGlinesTask($glineRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);

        self::assertSame('operserv.purge_expired_glines', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsConfiguredValue(): void
    {
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new PurgeExpiredGlinesTask($glineRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 7200);

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns360(): void
    {
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new PurgeExpiredGlinesTask($glineRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);

        self::assertSame(360, $task->getOrder());
    }

    #[Test]
    public function runRemovesExpiredGlinesAndLogsToDebug(): void
    {
        $expiredGline1 = $this->createStub(Gline::class);
        $expiredGline1->method('getMask')->willReturn('*@host1.com');
        $expiredGline1->method('getId')->willReturn(101);

        $expiredGline2 = $this->createStub(Gline::class);
        $expiredGline2->method('getMask')->willReturn('*@host2.com');
        $expiredGline2->method('getId')->willReturn(102);

        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->expects(self::once())->method('findExpired')->willReturn([$expiredGline1, $expiredGline2]);
        $glineRepo->expects(self::exactly(2))->method('remove');

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::exactly(2))->method('log')
            ->willReturnCallback(static function (string $operator, string $command, string $target, ?string $targetHost, ?string $targetIp, ?string $reason): void {
                self::assertSame(self::SERVER_NAME, $operator);
                self::assertSame('GLINE DEL', $command);
                self::assertSame('expired', $reason);
            });

        $task = new PurgeExpiredGlinesTask($glineRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenNoExpiredGlines(): void
    {
        $glineRepo = $this->createMock(GlineRepositoryInterface::class);
        $glineRepo->expects(self::once())->method('findExpired')->willReturn([]);
        $glineRepo->expects(self::never())->method('remove');

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::never())->method('log');

        $task = new PurgeExpiredGlinesTask($glineRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);
        $task->run();
    }

    #[Test]
    public function runRemovesCorrectGlineById(): void
    {
        $expiredGline = $this->createStub(Gline::class);
        $expiredGline->method('getMask')->willReturn('*@isp.com');
        $expiredGline->method('getId')->willReturn(999);

        $removed = [];
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findExpired')->willReturn([$expiredGline]);
        $glineRepo->method('remove')
            ->willReturnCallback(static function (Gline $gline) use (&$removed): void {
                $removed[] = $gline;
            });

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::once())->method('log')->with(
            self::SERVER_NAME,
            'GLINE DEL',
            '*@isp.com',
            null,
            null,
            'expired',
        );

        $task = new PurgeExpiredGlinesTask($glineRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);
        $task->run();

        self::assertCount(1, $removed);
    }
}
