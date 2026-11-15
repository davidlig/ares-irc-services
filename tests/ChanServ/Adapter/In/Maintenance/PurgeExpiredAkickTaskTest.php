<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\In\Maintenance\PurgeExpiredAkickTask;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelAkick;
use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

use function array_map;
use function array_shift;
use function count;
use function range;

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

    #[Test]
    public function loadsExpiredAkicksInBoundedBatchesAfterTheLastSeenId(): void
    {
        $loads = [];
        $deleted = [];
        $akickRepo = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akickRepo->expects(self::exactly(2))->method('findExpired')
            ->willReturnCallback(function (?ChannelAkick $after, ?int $limit) use (&$loads): array {
                $loads[] = [$after?->getId(), $limit];

                return null === $after ? [$this->expired(1), $this->expired(2)] : [$this->expired(3)];
            });
        $akickRepo->expects(self::exactly(2))->method('deleteByIds')
            ->willReturnCallback(static function (array $ids) use (&$deleted): int {
                $deleted[] = $ids;

                return count($ids);
            });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(3))->method('info');

        new PurgeExpiredAkickTask($akickRepo, $logger, 3600, 2)->run();

        self::assertSame([[null, 2], [2, 2]], $loads);
        self::assertSame([[1, 2], [3]], $deleted);
    }

    #[Test]
    public function capsTheBatchesPerCycleAndContinuesFromTheCursorNextCycle(): void
    {
        $loads = self::recorder();
        $deleted = self::recorder();
        $pages = [[1, 2], [3, 4], [5, 6], [7], [8, 9]];
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('findExpired')
            ->willReturnCallback(function (?ChannelAkick $after, ?int $limit) use ($loads, &$pages): array {
                $loads[] = [$after?->getId(), $limit];

                return array_map($this->expired(...), array_shift($pages) ?? []);
            });
        $akickRepo->method('deleteByIds')
            ->willReturnCallback(static function (array $ids) use ($deleted): int {
                $deleted[] = $ids;

                return count($ids);
            });
        $task = new PurgeExpiredAkickTask($akickRepo, new NullLogger(), 3600, 2, 2);

        $task->run();
        self::assertSame([[null, 2], [2, 2]], $loads->getArrayCopy(), 'the first cycle stops after two full batches');
        self::assertSame([[1, 2], [3, 4]], $deleted->getArrayCopy());

        $task->run();
        self::assertSame([[null, 2], [2, 2], [4, 2], [6, 2]], $loads->getArrayCopy(), 'the next cycle continues after the last deleted entry');
        self::assertSame([[1, 2], [3, 4], [5, 6], [7]], $deleted->getArrayCopy());

        $task->run();
        self::assertSame([null, 2], $loads[4], 'a short page ends the sweep, so the next cycle starts over');
        self::assertSame([8, 9], $deleted[4]);
    }

    #[Test]
    public function defaultsToTenBatchesOfOneHundredPerCycle(): void
    {
        $loads = 0;
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('findExpired')
            ->willReturnCallback(function (?ChannelAkick $after, ?int $limit) use (&$loads): array {
                ++$loads;
                self::assertSame(100, $limit);

                return array_map($this->expired(...), range($loads * 100 - 99, $loads * 100));
            });
        $akickRepo->method('deleteByIds')->willReturnCallback(static fn (array $ids): int => count($ids));

        new PurgeExpiredAkickTask($akickRepo, new NullLogger(), 3600)->run();

        self::assertSame(10, $loads);
    }

    #[Test]
    public function aFailedDeleteKeepsTheCursorSoTheBatchIsRetriedNextCycle(): void
    {
        $loads = self::recorder();
        $deletes = 0;
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('findExpired')
            ->willReturnCallback(function (?ChannelAkick $after, ?int $limit) use ($loads): array {
                $loads[] = $after?->getId();

                return null === $after ? [$this->expired(1), $this->expired(2)] : [$this->expired(3), $this->expired(4)];
            });
        $akickRepo->method('deleteByIds')->willReturnCallback(static function (array $ids) use (&$deletes): int {
            if (2 === ++$deletes) {
                throw new RuntimeException('database unavailable');
            }

            return count($ids);
        });
        $task = new PurgeExpiredAkickTask($akickRepo, new NullLogger(), 3600, 2, 2);

        $failure = null;
        try {
            $task->run();
        } catch (RuntimeException $e) {
            $failure = $e;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        $loads->exchangeArray([]);

        $task->run();

        self::assertSame(2, $loads[0], 'the next cycle resumes after the last deleted entry, not after the failed batch');
        self::assertSame(4, $deletes);
    }

    private function expired(int $id): ChannelAkick
    {
        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('getChannelId')->willReturn(1);
        $akick->method('getMask')->willReturn('*!*@host' . $id . '.test');
        $akick->method('getId')->willReturn($id);

        return $akick;
    }

    /** @return ArrayObject<int, mixed> */
    private static function recorder(): ArrayObject
    {
        return new ArrayObject();
    }
}
