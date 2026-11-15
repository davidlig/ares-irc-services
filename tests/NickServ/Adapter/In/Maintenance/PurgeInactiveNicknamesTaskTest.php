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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;

use function array_fill;
use function count;

#[CoversClass(PurgeInactiveNicknamesTask::class)]
final class PurgeInactiveNicknamesTaskTest extends TestCase
{
    #[Test]
    public function getNameReturnsTaskName(): void
    {
        $task = new PurgeInactiveNicknamesTask(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickDropService::class),
            new NullLogger(),
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
            new NullLogger(),
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
            new NullLogger(),
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
            new NullLogger(),
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
            new NullLogger(),
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
            new NullLogger(),
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
            new NullLogger(),
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
            new NullLogger(),
            $this->clock(),
            3600,
            90,
        );
        $task->run();
    }

    #[Test]
    public function aNickThatKeepsFailingIsLoggedAndSkippedWhileTheRestOfTheBatchIsDropped(): void
    {
        $failing = $this->nick(1, 'Broken');
        $healthy = $this->nick(2, 'Healthy');
        $failure = new RuntimeException('drop subscriber failed');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findRegisteredInactiveSince')->willReturn([$failing, $healthy]);
        $repo->method('findById')->willReturn($failing);
        $dropped = [];
        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::exactly(2))->method('dropNick')
            ->willReturnCallback(static function (RegisteredNick $nick) use ($failing, $failure, &$dropped): void {
                if ($nick === $failing) {
                    throw $failure;
                }
                $dropped[] = $nick->getNickname();
            });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Broken (id 1); skipping it until the next cycle.',
            ['exception' => $failure],
        );
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->expects(self::once())->method('clear');

        new PurgeInactiveNicknamesTask($repo, $dropService, $logger, $this->clock(), 3600, 90, $em)->run();

        self::assertSame(['Healthy'], $dropped);
    }

    #[Test]
    public function laterBatchesOfTheSameCycleSkipNicksThatAlreadyFailedWithoutShrinkingTheBatch(): void
    {
        $failing = $this->nick(1, 'Broken');
        $loads = [];
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::exactly(2))->method('findRegisteredInactiveSince')
            ->willReturnCallback(function (DateTimeImmutable $threshold, int $limit) use ($failing, &$loads): array {
                $loads[] = $limit;

                return 1 === count($loads)
                    ? [$failing, $this->nick(2, 'Second')]
                    : [$failing, $this->nick(3, 'Third'), $this->nick(4, 'Fourth')];
            });
        $repo->method('findById')->willReturn($failing);
        $attempts = [];
        $dropService = $this->createStub(NickDropService::class);
        $dropService->method('dropNick')
            ->willReturnCallback(static function (RegisteredNick $nick) use ($failing, &$attempts): void {
                $attempts[] = $nick->getNickname();
                if ($nick === $failing) {
                    throw new RuntimeException('drop subscriber failed');
                }
            });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        new PurgeInactiveNicknamesTask($repo, $dropService, $logger, $this->clock(), 3600, 90, batchSize: 2, maxBatchesPerCycle: 2)->run();

        self::assertSame([2, 3], $loads);
        self::assertSame(['Broken', 'Second', 'Third', 'Fourth'], $attempts);
    }

    #[Test]
    public function aFailureThatClosesTheEntityManagerStopsTheBatchAndFailsTheTask(): void
    {
        $failing = $this->nick(1, 'Broken');
        $failure = new RuntimeException('transaction rolled back');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findRegisteredInactiveSince')->willReturn([$failing, $this->nick(2, 'Healthy')]);
        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::once())->method('dropNick')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Broken (id 1); the EntityManager is closed, stopping this batch.',
            ['exception' => $failure],
        );
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(false);

        $this->expectExceptionObject($failure);

        new PurgeInactiveNicknamesTask($repo, $dropService, $logger, $this->clock(), 3600, 90, $em)->run();
    }

    #[Test]
    public function aFailureAfterTheDropCommittedIsLoggedAsDroppedAndTheBatchContinues(): void
    {
        $dropped = $this->nick(1, 'Gone');
        $failure = new RuntimeException('force rename failed');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findRegisteredInactiveSince')->willReturn([$dropped, $this->nick(2, 'Healthy')]);
        $repo->method('findById')->willReturn(null);
        $attempts = [];
        $dropService = $this->createStub(NickDropService::class);
        $dropService->method('dropNick')
            ->willReturnCallback(static function (RegisteredNick $nick) use ($dropped, $failure, &$attempts): void {
                $attempts[] = $nick->getNickname();
                if ($nick === $dropped) {
                    throw $failure;
                }
            });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Maintenance [nickserv.purge_inactive_nicknames]: dropped inactive nick Gone (id 1), but a post-drop step failed.',
            ['exception' => $failure],
        );
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);

        new PurgeInactiveNicknamesTask($repo, $dropService, $logger, $this->clock(), 3600, 90, $em)->run();

        self::assertSame(['Gone', 'Healthy'], $attempts);
    }

    private function nick(int $id, string $nickname): RegisteredNick
    {
        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getId')->willReturn($id);
        $nick->method('getNickname')->willReturn($nickname);

        return $nick;
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
