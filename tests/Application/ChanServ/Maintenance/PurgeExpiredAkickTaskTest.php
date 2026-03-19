<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Maintenance;

use App\Application\ChanServ\Maintenance\PurgeExpiredAkickTask;
use App\Domain\ChanServ\Entity\ChannelAkick;
use App\Domain\ChanServ\Repository\ChannelAkickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PurgeExpiredAkickTask::class)]
final class PurgeExpiredAkickTaskTest extends TestCase
{
    #[Test]
    public function getNameReturnsChanservPurgeExpiredAkick(): void
    {
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $task = new PurgeExpiredAkickTask($akickRepo, new NullLogger(), 3600);

        self::assertSame('chanserv.purge_expired_akick', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsConfiguredValue(): void
    {
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $task = new PurgeExpiredAkickTask($akickRepo, new NullLogger(), 7200);

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns350(): void
    {
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $task = new PurgeExpiredAkickTask($akickRepo, new NullLogger(), 3600);

        self::assertSame(350, $task->getOrder());
    }

    #[Test]
    public function runRemovesExpiredAkicks(): void
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

        $task = new PurgeExpiredAkickTask($akickRepo, new NullLogger(), 3600);
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenNoExpiredAkicks(): void
    {
        $akickRepo = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akickRepo->expects(self::once())->method('findExpired')->willReturn([]);
        $akickRepo->expects(self::never())->method('remove');

        $task = new PurgeExpiredAkickTask($akickRepo, new NullLogger(), 3600);
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

        $task = new PurgeExpiredAkickTask($akickRepo, new NullLogger(), 3600);
        $task->run();

        self::assertCount(1, $removed);
    }
}
