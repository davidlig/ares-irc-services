<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance;

use App\NickServ\Adapter\In\Maintenance\PurgeInactiveNicknamesTask;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
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
    /** Whether the database answers the liveness probe in the outage tests. */
    private bool $databaseUp = true;

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
    public function aNickThatClosesTheEntityManagerDuringADatabaseOutageIsNeverSkipped(): void
    {
        $pages = [];
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findRegisteredInactiveSince')
            ->willReturnCallback(function (DateTimeImmutable $threshold, int $limit, int $afterId) use (&$pages): array {
                $pages[] = $afterId;

                return array_values(array_filter(
                    [$this->nick(1, 'Stuck'), $this->nick(2, 'Later')],
                    static fn (RegisteredNick $nick): bool => $nick->getId() > $afterId,
                ));
            });
        $open = true;
        $this->databaseUp = false;
        $attempts = [];
        $dropService = $this->createStub(NickDropService::class);
        $dropService->method('dropNick')
            ->willReturnCallback(function (RegisteredNick $nick) use (&$open, &$attempts): void {
                $attempts[] = $nick->getNickname();
                if (!$this->databaseUp) {
                    $open = false;

                    throw new RuntimeException('connection refused');
                }
            });
        $errors = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use (&$errors): void {
            $errors[] = $message;
        });
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(function (): int {
            if (!$this->databaseUp) {
                throw new RuntimeException('connection refused');
            }

            return 1;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $task = new PurgeInactiveNicknamesTask($repo, $dropService, $logger, $this->clock(), 3600, 90, $em);

        $failures = 0;
        for ($cycle = 0; $cycle < 4; ++$cycle) {
            try {
                $task->run();
            } catch (RuntimeException) {
                ++$failures;
            }
            $open = true;
        }
        $this->databaseUp = true;
        $task->run();

        self::assertSame(4, $failures);
        self::assertSame([0, 0, 0, 0, 0], $pages);
        self::assertSame(['Stuck', 'Stuck', 'Stuck', 'Stuck', 'Stuck', 'Later'], $attempts);
        self::assertSame([
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Stuck (id 1); the EntityManager is closed, stopping this batch.',
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Stuck (id 1); the EntityManager is closed, stopping this batch.',
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Stuck (id 1); the EntityManager is closed, stopping this batch.',
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Stuck (id 1); the EntityManager is closed, stopping this batch.',
        ], $errors);
    }

    #[Test]
    public function aNickThatClosesTheEntityManagerThreeCyclesInARowIsSkippedSoLaterNicksAreProcessed(): void
    {
        $pages = [];
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findRegisteredInactiveSince')
            ->willReturnCallback(function (DateTimeImmutable $threshold, int $limit, int $afterId) use (&$pages): array {
                $pages[] = $afterId;

                return array_values(array_filter(
                    [$this->nick(1, 'Poison'), $this->nick(2, 'Healthy')],
                    static fn (RegisteredNick $nick): bool => $nick->getId() > $afterId,
                ));
            });
        $open = true;
        $attempts = [];
        $dropService = $this->createStub(NickDropService::class);
        $dropService->method('dropNick')
            ->willReturnCallback(static function (RegisteredNick $nick) use (&$open, &$attempts): void {
                $attempts[] = $nick->getNickname();
                if ('Poison' === $nick->getNickname()) {
                    $open = false;

                    throw new RuntimeException('foreign key violation');
                }
            });
        $errors = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use (&$errors): void {
            $errors[] = $message;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $task = new PurgeInactiveNicknamesTask($repo, $dropService, $logger, $this->clock(), 3600, 90, $em);

        $failures = 0;
        for ($cycle = 0; $cycle < 4; ++$cycle) {
            try {
                $task->run();
            } catch (RuntimeException) {
                ++$failures;
            }
            $open = true;
        }

        self::assertSame(3, $failures);
        self::assertSame([0, 0, 0, 1], $pages);
        self::assertSame(['Poison', 'Poison', 'Poison', 'Healthy'], $attempts);
        self::assertSame([
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Poison (id 1); the EntityManager is closed, stopping this batch.',
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Poison (id 1); the EntityManager is closed, stopping this batch.',
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Poison (id 1) with the EntityManager closed 3 times in a row; skipping it until the next sweep and stopping this batch.',
        ], $errors);
    }

    #[Test]
    public function aNickSkippedAfterItsAttemptsGetsOneAttemptPerLaterSweepInsteadOfAFreshBudget(): void
    {
        $pages = [];
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findRegisteredInactiveSince')
            ->willReturnCallback(function (DateTimeImmutable $threshold, int $limit, int $afterId) use (&$pages): array {
                $pages[] = $afterId;

                return array_values(array_filter(
                    [$this->nick(1, 'Poison'), $this->nick(2, 'Healthy')],
                    static fn (RegisteredNick $nick): bool => $nick->getId() > $afterId,
                ));
            });
        $open = true;
        $attempts = [];
        $dropService = $this->createStub(NickDropService::class);
        $dropService->method('dropNick')
            ->willReturnCallback(static function (RegisteredNick $nick) use (&$open, &$attempts): void {
                $attempts[] = $nick->getNickname();
                if ('Poison' === $nick->getNickname()) {
                    $open = false;

                    throw new RuntimeException('foreign key violation');
                }
            });
        $errors = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use (&$errors): void {
            $errors[] = $message;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $task = new PurgeInactiveNicknamesTask($repo, $dropService, $logger, $this->clock(), 3600, 90, $em);

        $failures = 0;
        for ($cycle = 0; $cycle < 8; ++$cycle) {
            try {
                $task->run();
            } catch (RuntimeException) {
                ++$failures;
            }
            $open = true;
        }

        self::assertSame(5, $failures);
        self::assertSame([0, 0, 0, 1, 0, 1, 0, 1], $pages);
        self::assertSame(['Poison', 'Poison', 'Poison', 'Healthy', 'Poison', 'Healthy', 'Poison', 'Healthy'], $attempts);
        self::assertSame(
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Poison (id 1) again after it was skipped in an earlier sweep; skipping it until the next sweep and stopping this batch.',
            $errors[3],
        );
        self::assertSame($errors[3], $errors[4]);
    }

    #[Test]
    public function aSkippedNickThatFailsOnItsOwnInALaterSweepIsStillSkippedAtOnceWhenItClosesTheEntityManagerAgain(): void
    {
        $pages = [];
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findRegisteredInactiveSince')
            ->willReturnCallback(function (DateTimeImmutable $threshold, int $limit, int $afterId) use (&$pages): array {
                $pages[] = $afterId;

                return array_values(array_filter(
                    [$this->nick(1, 'Poison'), $this->nick(2, 'Healthy')],
                    static fn (RegisteredNick $nick): bool => $nick->getId() > $afterId,
                ));
            });
        $repo->method('findById')->willReturnCallback(fn (int $id): RegisteredNick => $this->nick($id, 'Poison'));
        $open = true;
        $attempts = [];
        $dropService = $this->createStub(NickDropService::class);
        $dropService->method('dropNick')
            ->willReturnCallback(static function (RegisteredNick $nick) use (&$open, &$attempts): void {
                $attempts[] = $nick->getNickname();
                if ('Poison' !== $nick->getNickname()) {
                    return;
                }
                // The fourth attempt fails on its own; every other one closes the EntityManager.
                if (4 !== count(array_keys($attempts, 'Poison', true))) {
                    $open = false;
                }

                throw new RuntimeException('foreign key violation');
            });
        $errors = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use (&$errors): void {
            $errors[] = $message;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $task = new PurgeInactiveNicknamesTask($repo, $dropService, $logger, $this->clock(), 3600, 90, $em);

        $failures = 0;
        for ($cycle = 0; $cycle < 7; ++$cycle) {
            try {
                $task->run();
            } catch (RuntimeException) {
                ++$failures;
            }
            $open = true;
        }

        self::assertSame(4, $failures);
        self::assertSame([0, 0, 0, 1, 0, 0, 1], $pages);
        self::assertSame(['Poison', 'Poison', 'Poison', 'Healthy', 'Poison', 'Healthy', 'Poison', 'Healthy'], $attempts);
        self::assertSame([
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Poison (id 1) with the EntityManager closed 3 times in a row; skipping it until the next sweep and stopping this batch.',
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Poison (id 1); skipping it until the next sweep.',
            'Maintenance [nickserv.purge_inactive_nicknames]: failed to drop inactive nick Poison (id 1) again after it was skipped in an earlier sweep; skipping it until the next sweep and stopping this batch.',
        ], array_slice($errors, 2));
    }

    #[Test]
    public function theClosedEntityManagerAttemptsRestartAfterTheNickIsProcessed(): void
    {
        $pages = [];
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findRegisteredInactiveSince')
            ->willReturnCallback(function (DateTimeImmutable $threshold, int $limit, int $afterId) use (&$pages): array {
                $pages[] = $afterId;

                return array_values(array_filter(
                    [$this->nick(1, 'Flaky'), $this->nick(2, 'Healthy')],
                    static fn (RegisteredNick $nick): bool => $nick->getId() > $afterId,
                ));
            });
        $open = true;
        $flakyAttempts = 0;
        $dropService = $this->createStub(NickDropService::class);
        $dropService->method('dropNick')
            ->willReturnCallback(static function (RegisteredNick $nick) use (&$open, &$flakyAttempts): void {
                if ('Flaky' === $nick->getNickname() && 3 !== ++$flakyAttempts) {
                    $open = false;

                    throw new RuntimeException('lock wait timeout');
                }
            });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $task = new PurgeInactiveNicknamesTask($repo, $dropService, new NullLogger(), $this->clock(), 3600, 90, $em);

        for ($cycle = 0; $cycle < 6; ++$cycle) {
            try {
                $task->run();
            } catch (RuntimeException) {
            }
            $open = true;
        }

        self::assertSame([0, 0, 0, 0, 0, 0], $pages);
        self::assertSame(6, $flakyAttempts);
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
