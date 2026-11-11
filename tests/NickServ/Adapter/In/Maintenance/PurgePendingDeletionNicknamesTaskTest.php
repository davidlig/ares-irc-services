<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance;

use App\NickServ\Adapter\In\Maintenance\PurgePendingDeletionNicknamesTask;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_fill;

#[CoversClass(PurgePendingDeletionNicknamesTask::class)]
final class PurgePendingDeletionNicknamesTaskTest extends TestCase
{
    #[Test]
    public function metadataReturnsExpectedValues(): void
    {
        $task = new PurgePendingDeletionNicknamesTask(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickDropService::class),
            $this->clock(),
            3600,
            7,
        );

        self::assertSame('nickserv.purge_pending_deletion_nicknames', $task->getName());
        self::assertSame(3600, $task->getIntervalSeconds());
        self::assertSame(210, $task->getOrder());
    }

    #[Test]
    public function runHardDropsExpiredPendingDeletionNicks(): void
    {
        $nick = $this->createStub(RegisteredNick::class);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findPendingDeletionBefore')
            ->with($this->now()->modify('-7 days'), 100)
            ->willReturn([$nick, new stdClass()]);

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::once())->method('hardDropNick')->with(
            $nick,
            $this->now(),
            'manual-grace-expired',
            null,
        );

        $task = new PurgePendingDeletionNicknamesTask($repo, $dropService, $this->clock(), 3600, 7);

        $task->run();
    }

    #[Test]
    public function runCarriesRemainderToNextCycleAndClearsEntityManager(): void
    {
        $nicks = array_fill(0, 10, $this->createStub(RegisteredNick::class));

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findPendingDeletionBefore')
            ->with($this->now()->modify('-7 days'), 10)
            ->willReturn($nicks);

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::exactly(10))->method('hardDropNick');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('clear');

        $task = new PurgePendingDeletionNicknamesTask(
            $repo,
            $dropService,
            $this->clock(),
            3600,
            7,
            $em,
            batchSize: 10,
            maxBatchesPerCycle: 1,
        );
        $task->run();
    }

    #[Test]
    public function runClearsEntityManagerBetweenBatches(): void
    {
        $nickA = $this->createStub(RegisteredNick::class);
        $nickB = $this->createStub(RegisteredNick::class);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::exactly(2))
            ->method('findPendingDeletionBefore')
            ->with($this->now()->modify('-7 days'), 1)
            ->willReturnOnConsecutiveCalls([$nickA], [$nickB]);

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::exactly(2))->method('hardDropNick');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('clear');

        $task = new PurgePendingDeletionNicknamesTask(
            $repo,
            $dropService,
            $this->clock(),
            3600,
            7,
            $em,
            batchSize: 1,
            maxBatchesPerCycle: 2,
        );
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
