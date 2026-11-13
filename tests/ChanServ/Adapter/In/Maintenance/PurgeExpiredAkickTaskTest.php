<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\In\Maintenance\PurgeExpiredAkickTask;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelAkick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function count;

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
    public function runRemovesExpiredAkicksAndLogsToSystemLogger(): void
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
        $akickRepo->expects(self::never())->method('remove');
        $akickRepo->expects(self::once())->method('deleteByIds')->with([101, 102]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('info');

        $task = new PurgeExpiredAkickTask($akickRepo, $logger, 3600);
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenNoExpiredAkicks(): void
    {
        $akickRepo = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akickRepo->expects(self::once())->method('findExpired')->willReturn([]);
        $akickRepo->expects(self::never())->method('remove');
        $akickRepo->expects(self::never())->method('deleteByIds');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $task = new PurgeExpiredAkickTask($akickRepo, $logger, 3600);
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

        $deletedIds = [];
        $akickRepo = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akickRepo->expects(self::once())->method('findExpired')->willReturn([$expiredAkick]);
        $akickRepo->expects(self::never())->method('remove');
        $akickRepo->expects(self::once())->method('deleteByIds')
            ->willReturnCallback(static function (array $ids) use (&$deletedIds): int {
                $deletedIds = $ids;

                return count($ids);
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::stringContains('Maintenance [chanserv.purge_expired_akick]: removed expired AKICK *!*@*.isp.com (id 999) from channel 5.'),
        );

        $task = new PurgeExpiredAkickTask($akickRepo, $logger, 3600);
        $task->run();

        self::assertSame([999], $deletedIds);
    }
}
