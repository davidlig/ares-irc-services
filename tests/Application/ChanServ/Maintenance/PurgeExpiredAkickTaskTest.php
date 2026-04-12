<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Maintenance;

use App\Application\ChanServ\Maintenance\PurgeExpiredAkickTask;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\ChanServ\Entity\ChannelAkick;
use App\Domain\ChanServ\Repository\ChannelAkickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PurgeExpiredAkickTask::class)]
final class PurgeExpiredAkickTaskTest extends TestCase
{
    private const string SERVER_NAME = 'test-server.example.com';

    #[Test]
    public function getNameReturnsChanservPurgeExpiredAkick(): void
    {
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new PurgeExpiredAkickTask($akickRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);

        self::assertSame('chanserv.purge_expired_akick', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsConfiguredValue(): void
    {
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new PurgeExpiredAkickTask($akickRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 7200);

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns350(): void
    {
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new PurgeExpiredAkickTask($akickRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);

        self::assertSame(350, $task->getOrder());
    }

    #[Test]
    public function runRemovesExpiredAkicksAndLogsToDebug(): void
    {
        $expiredAkick1 = $this->createStub(ChannelAkick::class);
        $expiredAkick1->method('isExpired')->willReturn(true);
        $expiredAkick1->method('getChannelId')->willReturn(1);
        $expiredAkick1->method('getMask')->willReturn('*!*@*.spam.com');
        $expiredAkick1->method('getId')->willReturn(101);

        $expiredAkick2 = $this->createStub(ChannelAkick::class);
        $expiredAkick2->method('isExpired')->willReturn(true);
        $expiredAkick2->method('getChannelId')->willReturn(1);
        $expiredAkick2->method('getMask')->willReturn('*!*@*.bad.com');
        $expiredAkick2->method('getId')->willReturn(102);

        $akickRepo = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akickRepo->expects(self::once())->method('findExpired')->willReturn([$expiredAkick1, $expiredAkick2]);
        $akickRepo->expects(self::exactly(2))->method('remove');

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::exactly(2))->method('log')
            ->willReturnCallback(static function (string $operator, string $command, string $target, ?string $targetHost, ?string $targetIp, ?string $reason): void {
                self::assertSame(self::SERVER_NAME, $operator);
                self::assertSame('AKICK DEL', $command);
                self::assertSame('expired', $reason);
            });

        $task = new PurgeExpiredAkickTask($akickRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenNoExpiredAkicks(): void
    {
        $akickRepo = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akickRepo->expects(self::once())->method('findExpired')->willReturn([]);
        $akickRepo->expects(self::never())->method('remove');

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::never())->method('log');

        $task = new PurgeExpiredAkickTask($akickRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);
        $task->run();
    }

    #[Test]
    public function runRemovesCorrectAkickById(): void
    {
        $expiredAkick = $this->createStub(ChannelAkick::class);
        $expiredAkick->method('isExpired')->willReturn(true);
        $expiredAkick->method('getChannelId')->willReturn(5);
        $expiredAkick->method('getMask')->willReturn('*!*@*.isp.com');
        $expiredAkick->method('getId')->willReturn(999);

        $removed = [];
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('findExpired')->willReturn([$expiredAkick]);
        $akickRepo->method('remove')
            ->willReturnCallback(static function (ChannelAkick $akick) use (&$removed): void {
                $removed[] = $akick;
            });

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::once())->method('log')->with(
            self::SERVER_NAME,
            'AKICK DEL',
            '*!*@*.isp.com',
            null,
            null,
            'expired',
        );

        $task = new PurgeExpiredAkickTask($akickRepo, $debugNotifier, new NullLogger(), self::SERVER_NAME, 3600);
        $task->run();

        self::assertCount(1, $removed);
    }
}
