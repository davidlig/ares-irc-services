<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance;

use App\NickServ\Adapter\In\Maintenance\PurgeInactiveNicknamesTask;
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

#[CoversClass(PurgeInactiveNicknamesTask::class)]
final class PurgeInactiveNicknamesTaskTest extends TestCase
{
    #[Test]
    public function getNameReturnsTaskName(): void
    {
        $task = new PurgeInactiveNicknamesTask(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickDropService::class),
            $this->clock(),
            3600,
            90,
        );

        self::assertSame('nickserv.purge_inactive_nicknames', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsAndGetOrderReturnInjectedValues(): void
    {
        $task = new PurgeInactiveNicknamesTask(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickDropService::class),
            $this->clock(),
            7200,
            60,
        );

        self::assertSame(7200, $task->getIntervalSeconds());
        self::assertSame(200, $task->getOrder());
    }

    #[Test]
    public function runDoesNothingWhenInactivityExpiryDaysIsZero(): void
    {
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::never())->method('findRegisteredInactiveSince');

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::never())->method('dropNick');

        $task = new PurgeInactiveNicknamesTask(
            $repo,
            $dropService,
            $this->clock(),
            3600,
            0,
        );
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenNoInactiveNicksExist(): void
    {
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findRegisteredInactiveSince')
            ->with($this->now()->modify('-90 days'), 100)
            ->willReturn([]);

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::never())->method('dropNick');

        $task = new PurgeInactiveNicknamesTask(
            $repo,
            $dropService,
            $this->clock(),
            3600,
            90,
        );
        $task->run();
    }

    #[Test]
    public function runCallsDropServiceForEachInactiveNick(): void
    {
        $nick = $this->createStub(RegisteredNick::class);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findRegisteredInactiveSince')
            ->with($this->now()->modify('-90 days'), 100)
            ->willReturn([$nick]);

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::once())
            ->method('dropNick')
            ->with($nick, $this->now(), 'inactivity', null);

        $task = new PurgeInactiveNicknamesTask(
            $repo,
            $dropService,
            $this->clock(),
            3600,
            90,
        );
        $task->run();
    }

    #[Test]
    public function runCarriesRemainingInactiveNicksToNextCycleAndClearsEntityManager(): void
    {
        $nicks = array_fill(0, 10, $this->createStub(RegisteredNick::class));

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findRegisteredInactiveSince')
            ->with($this->now()->modify('-90 days'), 10)
            ->willReturn($nicks);

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::exactly(10))->method('dropNick');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('clear');

        $task = new PurgeInactiveNicknamesTask(
            $repo,
            $dropService,
            $this->clock(),
            3600,
            90,
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
            ->method('findRegisteredInactiveSince')
            ->with($this->now()->modify('-90 days'), 1)
            ->willReturnOnConsecutiveCalls([$nickA], [$nickB]);

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::exactly(2))->method('dropNick');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('clear');

        $task = new PurgeInactiveNicknamesTask(
            $repo,
            $dropService,
            $this->clock(),
            3600,
            90,
            $em,
            batchSize: 1,
            maxBatchesPerCycle: 2,
        );
        $task->run();
    }

    #[Test]
    public function runSkipsNonRegisteredNickInstancesInResult(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findRegisteredInactiveSince')->willReturn([new stdClass()]);

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::never())->method('dropNick');

        $task = new PurgeInactiveNicknamesTask(
            $repo,
            $dropService,
            $this->clock(),
            3600,
            90,
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
