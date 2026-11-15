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
use function array_filter;
use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function range;

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
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Broken (id 1); skipping it until the next sweep.',
            ['exception' => $failure],
        );
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->expects(self::once())->method('clear');

        new PurgeInactiveNicknamesTask($repo, $dropService, $logger, $this->clock(), 3600, 90, $em)->run();

        self::assertSame(['Healthy'], $dropped);
    }

    #[Test]
    public function aFullPageOfNicksThatKeepFailingDoesNotBlockLaterNicksInTheNextCycle(): void
    {
        $rows = [];
        for ($id = 1; $id <= 101; ++$id) {
            $rows[$id] = $this->nick($id, 'Nick' . $id);
        }
        $pages = [];
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findRegisteredInactiveSince')
            ->willReturnCallback(static function (DateTimeImmutable $threshold, int $limit, int $afterId = 0) use (&$rows, &$pages): array {
                $pages[] = [$limit, $afterId];

                return array_slice(array_values(array_filter($rows, static fn (RegisteredNick $nick): bool => $nick->getId() > $afterId)), 0, $limit);
            });
        $repo->method('findById')->willReturnCallback(static fn (int $id): ?RegisteredNick => $rows[$id] ?? null);
        $attempts = [];
        $dropService = $this->createStub(NickDropService::class);
        $dropService->method('dropNick')
            ->willReturnCallback(static function (RegisteredNick $nick) use (&$rows, &$attempts): void {
                $attempts[] = $nick->getId();
                if ($nick->getId() <= 100) {
                    throw new RuntimeException('drop subscriber failed');
                }
                unset($rows[$nick->getId()]);
            });
        $errors = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use (&$errors): void {
            $errors[] = $message;
        });
        $task = new PurgeInactiveNicknamesTask($repo, $dropService, $logger, $this->clock(), 3600, 90);

        $task->run();
        $task->run();
        $task->run();

        self::assertSame([[100, 0], [100, 100], [100, 0]], $pages);
        self::assertSame([...range(1, 100), 101, ...range(1, 100)], $attempts);
        self::assertSame(range(1, 100), array_keys($rows));
        self::assertCount(200, $errors);
        self::assertSame('Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Nick1 (id 1); skipping it until the next sweep.', $errors[0]);
    }

    #[Test]
    public function laterBatchesOfTheSameCycleContinueAfterTheLastVisitedNickWithoutShrinkingTheBatch(): void
    {
        $failing = $this->nick(1, 'Broken');
        $loads = [];
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::exactly(2))->method('findRegisteredInactiveSince')
            ->willReturnCallback(function (DateTimeImmutable $threshold, int $limit, int $afterId) use ($failing, &$loads): array {
                $loads[] = [$limit, $afterId];

                return 1 === count($loads)
                    ? [$failing, $this->nick(2, 'Second')]
                    : [$this->nick(3, 'Third'), $this->nick(4, 'Fourth')];
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

        self::assertSame([[2, 0], [2, 2]], $loads);
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
    public function aNickWhoseDropClosedTheEntityManagerIsRetriedFirstInTheNextCycle(): void
    {
        $pages = [];
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findRegisteredInactiveSince')
            ->willReturnCallback(function (DateTimeImmutable $threshold, int $limit, int $afterId) use (&$pages): array {
                $pages[] = $afterId;

                return array_values(array_filter(
                    [$this->nick(1, 'First'), $this->nick(2, 'Second'), $this->nick(3, 'Third')],
                    static fn (RegisteredNick $nick): bool => $nick->getId() > $afterId,
                ));
            });
        $open = true;
        $attempts = [];
        $dropService = $this->createStub(NickDropService::class);
        $dropService->method('dropNick')
            ->willReturnCallback(static function (RegisteredNick $nick) use (&$open, &$attempts): void {
                $attempts[] = $nick->getNickname();
                if ('Second' === $nick->getNickname() && 2 === count($attempts)) {
                    $open = false;

                    throw new RuntimeException('transaction rolled back');
                }
            });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $task = new PurgeInactiveNicknamesTask($repo, $dropService, new NullLogger(), $this->clock(), 3600, 90, $em);

        try {
            $task->run();
            self::fail('The closed EntityManager must fail the task.');
        } catch (RuntimeException $e) {
            self::assertSame('transaction rolled back', $e->getMessage());
        }
        $open = true;
        $task->run();

        self::assertSame([0, 1], $pages);
        self::assertSame(['First', 'Second', 'Second', 'Third'], $attempts);
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
