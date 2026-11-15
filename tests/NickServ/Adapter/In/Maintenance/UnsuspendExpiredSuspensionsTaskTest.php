<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\NickServ\Adapter\In\Maintenance\UnsuspendExpiredSuspensionsTask;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickUnsuspendedEvent;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

use function array_filter;
use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function range;

#[CoversClass(UnsuspendExpiredSuspensionsTask::class)]
final class UnsuspendExpiredSuspensionsTaskTest extends TestCase
{
    private const string SERVER_NAME = 'test-server.example.com';

    #[Test]
    public function getNameReturnsNickservUnsuspendExpiredSuspensions(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, new NullLogger(), $this->clock(), $this->createStub(NickServEventPublisher::class), self::SERVER_NAME, 3600);

        self::assertSame('nickserv.unsuspend_expired_suspensions', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsConfiguredValue(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, new NullLogger(), $this->clock(), $this->createStub(NickServEventPublisher::class), self::SERVER_NAME, 7200);

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns195(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, new NullLogger(), $this->clock(), $this->createStub(NickServEventPublisher::class), self::SERVER_NAME, 3600);

        self::assertSame(195, $task->getOrder());
    }

    #[Test]
    public function runUnsuspendsExpiredSuspensionsAndLogsToDebug(): void
    {
        $nick1 = $this->createNickWithId('Nick1', 1);
        $nick1->suspend('Expired ban', new DateTimeImmutable('-1 hour'));

        $nick2 = $this->createNickWithId('Nick2', 2);
        $nick2->suspend('Expired ban', new DateTimeImmutable('-1 day'));

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findExpiredSuspensions')->with($this->now(), 100)->willReturn([$nick1, $nick2]);
        $savedIds = [];
        $nickRepo->expects(self::exactly(2))->method('save')
            ->willReturnCallback(static function (RegisteredNick $nick) use (&$savedIds): void {
                self::assertFalse($nick->isSuspended());
                $savedIds[] = $nick->getId();
            });

        $publishedIds = [];
        $eventPublisher = $this->createMock(NickServEventPublisher::class);
        $eventPublisher->expects(self::exactly(2))->method('publish')
            ->willReturnCallback(function (NickUnsuspendedEvent $event) use (&$savedIds, &$publishedIds): void {
                self::assertContains($event->nickId, $savedIds);
                self::assertSame([1 => 'Nick1', 2 => 'Nick2'][$event->nickId], $event->nickname);
                self::assertSame(self::SERVER_NAME, $event->performedBy);
                self::assertNull($event->performedByNickId);
                self::assertSame('*', $event->performedByIp);
                self::assertSame('*', $event->performedByHost);
                self::assertSame($this->now()->getTimestamp(), $event->occurredAt->getTimestamp());
                $publishedIds[] = $event->nickId;
            });

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::exactly(2))->method('log')
            ->willReturnCallback(static function (string $operator, string $command, string $target): void {
                self::assertSame(self::SERVER_NAME, $operator);
                self::assertSame('UNSUSPEND', $command);
            });

        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, new NullLogger(), $this->clock(), $eventPublisher, self::SERVER_NAME, 3600);
        $task->run();

        self::assertSame([1, 2], $publishedIds);
        self::assertFalse($nick1->isSuspended());
        self::assertFalse($nick2->isSuspended());
    }

    #[Test]
    public function runCarriesRemainingSuspensionsToNextCycleAndClearsEntityManager(): void
    {
        $nicks = [
            $this->createNickWithId('Sus1', 1),
            $this->createNickWithId('Sus2', 2),
        ];
        $nicks[0]->suspend('Ban', new DateTimeImmutable('-1 hour'));
        $nicks[1]->suspend('Ban', new DateTimeImmutable('-1 hour'));

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findExpiredSuspensions')->with($this->now(), 2)->willReturn($nicks);
        $nickRepo->expects(self::exactly(2))->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('clear');

        $task = new UnsuspendExpiredSuspensionsTask(
            $nickRepo,
            $this->createStub(ServiceDebugNotifierInterface::class),
            new NullLogger(),
            $this->clock(),
            $this->createStub(NickServEventPublisher::class),
            self::SERVER_NAME,
            3600,
            $em,
            batchSize: 2,
            maxBatchesPerCycle: 1,
        );
        $task->run();
    }

    #[Test]
    public function runClearsEntityManagerBetweenBatches(): void
    {
        $nick1 = $this->createNickWithId('Sus1', 1);
        $nick1->suspend('Ban', new DateTimeImmutable('-1 hour'));
        $nick2 = $this->createNickWithId('Sus2', 2);
        $nick2->suspend('Ban', new DateTimeImmutable('-1 hour'));

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::exactly(2))->method('findExpiredSuspensions')->with($this->now(), 1)->willReturnOnConsecutiveCalls([$nick1], [$nick2]);
        $nickRepo->expects(self::exactly(2))->method('save');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('clear');

        $task = new UnsuspendExpiredSuspensionsTask(
            $nickRepo,
            $this->createStub(ServiceDebugNotifierInterface::class),
            new NullLogger(),
            $this->clock(),
            $this->createStub(NickServEventPublisher::class),
            self::SERVER_NAME,
            3600,
            $em,
            batchSize: 1,
            maxBatchesPerCycle: 2,
        );
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenNoExpiredSuspensions(): void
    {
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findExpiredSuspensions')->with($this->now())->willReturn([]);
        $nickRepo->expects(self::never())->method('save');

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::never())->method('log');
        $eventPublisher = $this->createMock(NickServEventPublisher::class);
        $eventPublisher->expects(self::never())->method('publish');

        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, new NullLogger(), $this->clock(), $eventPublisher, self::SERVER_NAME, 3600);
        $task->run();
    }

    #[Test]
    public function runUnsuspendsCorrectNickById(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);
        $nick->suspend('Expired suspension', new DateTimeImmutable('-1 hour'));

        self::assertTrue($nick->isSuspended());

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findExpiredSuspensions')->willReturn([$nick]);

        $unsuspended = [];
        $nickRepo->method('save')
            ->willReturnCallback(static function (RegisteredNick $n) use (&$unsuspended): void {
                $unsuspended[] = $n;
            });

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::once())->method('log')->with(
            self::SERVER_NAME,
            'UNSUSPEND',
            'TestNick',
        );

        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, new NullLogger(), $this->clock(), $this->createStub(NickServEventPublisher::class), self::SERVER_NAME, 3600);
        $task->run();

        self::assertCount(1, $unsuspended);
        self::assertFalse($nick->isSuspended());
        self::assertNull($nick->getReason());
        self::assertNull($nick->getSuspendedUntil());
    }

    #[Test]
    public function aSuspensionThatKeepsFailingIsLoggedAndSkippedWhileTheRestOfTheBatchIsLifted(): void
    {
        $failing = $this->createNickWithId('Broken', 1);
        $failing->suspend('Ban', new DateTimeImmutable('-1 hour'));
        $healthy = $this->createNickWithId('Healthy', 2);
        $healthy->suspend('Ban', new DateTimeImmutable('-1 hour'));
        $failure = new RuntimeException('database rejected the update');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findExpiredSuspensions')->willReturn([$failing, $healthy]);
        $nickRepo->method('save')->willReturnCallback(static function (RegisteredNick $nick) use ($failing, $failure): void {
            if ($nick === $failing) {
                throw $failure;
            }
        });
        $published = [];
        $eventPublisher = $this->createStub(NickServEventPublisher::class);
        $eventPublisher->method('publish')->willReturnCallback(static function (NickUnsuspendedEvent $event) use (&$published): void {
            $published[] = $event->nickname;
        });
        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::once())->method('log')->with(self::SERVER_NAME, 'UNSUSPEND', 'Healthy');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Maintenance [nickserv.unsuspend_expired_suspensions]: failed to auto-unsuspend nick Broken (id 1); skipping it until the next sweep.',
            ['exception' => $failure],
        );
        $logger->expects(self::once())->method('info')->with('Maintenance [nickserv.unsuspend_expired_suspensions]: auto-unsuspended nick Healthy (id 2).');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->expects(self::once())->method('detach')->with($failing);
        $em->expects(self::once())->method('clear');

        new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, $logger, $this->clock(), $eventPublisher, self::SERVER_NAME, 3600, $em)->run();

        self::assertSame(['Healthy'], $published);
        self::assertFalse($healthy->isSuspended());
    }

    #[Test]
    public function laterBatchesOfTheSameCycleContinueAfterTheLastVisitedSuspensionWithoutShrinkingTheBatch(): void
    {
        $failing = $this->createNickWithId('Broken', 1);
        $second = $this->createNickWithId('Second', 2);
        $third = $this->createNickWithId('Third', 3);
        $fourth = $this->createNickWithId('Fourth', 4);
        foreach ([$failing, $second, $third, $fourth] as $nick) {
            $nick->suspend('Ban', new DateTimeImmutable('-1 hour'));
        }
        $loads = [];
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::exactly(2))->method('findExpiredSuspensions')
            ->willReturnCallback(static function (DateTimeImmutable $now, int $limit, int $afterId) use ($failing, $second, $third, $fourth, &$loads): array {
                $loads[] = [$limit, $afterId];

                return 1 === count($loads) ? [$failing, $second] : [$third, $fourth];
            });
        $attempts = [];
        $nickRepo->method('save')->willReturnCallback(static function (RegisteredNick $nick) use ($failing, &$attempts): void {
            $attempts[] = $nick->getNickname();
            if ($nick === $failing) {
                throw new RuntimeException('database rejected the update');
            }
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        new UnsuspendExpiredSuspensionsTask(
            $nickRepo,
            $this->createStub(ServiceDebugNotifierInterface::class),
            $logger,
            $this->clock(),
            $this->createStub(NickServEventPublisher::class),
            self::SERVER_NAME,
            3600,
            batchSize: 2,
            maxBatchesPerCycle: 2,
        )->run();

        self::assertSame([[2, 0], [2, 2]], $loads);
        self::assertSame(['Broken', 'Second', 'Third', 'Fourth'], $attempts);
    }

    #[Test]
    public function aFullPageOfSuspensionsThatKeepFailingDoesNotBlockLaterSuspensionsInTheNextCycle(): void
    {
        $rows = [];
        for ($id = 1; $id <= 101; ++$id) {
            $rows[$id] = $this->createNickWithId('Nick' . $id, $id);
            $rows[$id]->suspend('Ban', new DateTimeImmutable('-1 hour'));
        }
        $pages = [];
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findExpiredSuspensions')
            ->willReturnCallback(static function (DateTimeImmutable $now, int $limit, int $afterId = 0) use (&$rows, &$pages): array {
                $pages[] = [$limit, $afterId];

                return array_slice(array_values(array_filter($rows, static fn (RegisteredNick $nick): bool => $nick->getId() > $afterId)), 0, $limit);
            });
        $attempts = [];
        $nickRepo->method('save')->willReturnCallback(static function (RegisteredNick $nick) use (&$rows, &$attempts): void {
            $attempts[] = $nick->getId();
            if ($nick->getId() <= 100) {
                $nick->suspend('Ban', new DateTimeImmutable('-1 hour'));

                throw new RuntimeException('database rejected the update');
            }
            unset($rows[$nick->getId()]);
        });
        $errors = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function (string $message) use (&$errors): void {
            $errors[] = $message;
        });
        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $this->createStub(ServiceDebugNotifierInterface::class), $logger, $this->clock(), $this->createStub(NickServEventPublisher::class), self::SERVER_NAME, 3600);

        $task->run();
        $task->run();
        $task->run();

        self::assertSame([[100, 0], [100, 100], [100, 0]], $pages);
        self::assertSame([...range(1, 100), 101, ...range(1, 100)], $attempts);
        self::assertSame(range(1, 100), array_keys($rows));
        self::assertCount(200, $errors);
        self::assertSame('Maintenance [nickserv.unsuspend_expired_suspensions]: failed to auto-unsuspend nick Nick1 (id 1); skipping it until the next sweep.', $errors[0]);
    }

    #[Test]
    public function aSaveFailureThatClosesTheEntityManagerStopsTheBatchAndFailsTheTask(): void
    {
        $failing = $this->createNickWithId('Broken', 1);
        $failing->suspend('Ban', new DateTimeImmutable('-1 hour'));
        $healthy = $this->createNickWithId('Healthy', 2);
        $healthy->suspend('Ban', new DateTimeImmutable('-1 hour'));
        $failure = new RuntimeException('flush failed');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findExpiredSuspensions')->willReturn([$failing, $healthy]);
        $nickRepo->expects(self::once())->method('save')->with($failing)->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Maintenance [nickserv.unsuspend_expired_suspensions]: failed to auto-unsuspend nick Broken (id 1); the EntityManager is closed, stopping this batch.',
            ['exception' => $failure],
        );
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(false);

        $this->expectExceptionObject($failure);

        new UnsuspendExpiredSuspensionsTask($nickRepo, $this->createStub(ServiceDebugNotifierInterface::class), $logger, $this->clock(), $this->createStub(NickServEventPublisher::class), self::SERVER_NAME, 3600, $em)->run();
    }

    #[Test]
    public function aSuspensionWhoseSaveClosedTheEntityManagerIsRetriedFirstInTheNextCycle(): void
    {
        $nicks = [];
        foreach (['First', 'Second', 'Third'] as $index => $nickname) {
            $nicks[] = $this->createNickWithId($nickname, $index + 1);
        }
        $pages = [];
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findExpiredSuspensions')
            ->willReturnCallback(static function (DateTimeImmutable $now, int $limit, int $afterId) use ($nicks, &$pages): array {
                $pages[] = $afterId;

                return array_values(array_filter($nicks, static fn (RegisteredNick $nick): bool => $nick->getId() > $afterId));
            });
        $open = true;
        $attempts = [];
        $nickRepo->method('save')->willReturnCallback(static function (RegisteredNick $nick) use (&$open, &$attempts): void {
            $attempts[] = $nick->getNickname();
            if ('Second' === $nick->getNickname() && 2 === count($attempts)) {
                $open = false;

                throw new RuntimeException('flush failed');
            }
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $this->createStub(ServiceDebugNotifierInterface::class), new NullLogger(), $this->clock(), $this->createStub(NickServEventPublisher::class), self::SERVER_NAME, 3600, $em);

        try {
            $task->run();
            self::fail('The closed EntityManager must fail the task.');
        } catch (RuntimeException $e) {
            self::assertSame('flush failed', $e->getMessage());
        }
        $open = true;
        $task->run();

        self::assertSame([0, 1], $pages);
        self::assertSame(['First', 'Second', 'Second', 'Third'], $attempts);
    }

    #[Test]
    public function aSuspensionThatClosesTheEntityManagerThreeCyclesInARowIsSkippedSoLaterSuspensionsAreProcessed(): void
    {
        $nicks = [$this->createNickWithId('Poison', 1), $this->createNickWithId('Healthy', 2)];
        $pages = [];
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findExpiredSuspensions')
            ->willReturnCallback(static function (DateTimeImmutable $now, int $limit, int $afterId) use ($nicks, &$pages): array {
                $pages[] = $afterId;

                return array_values(array_filter($nicks, static fn (RegisteredNick $nick): bool => $nick->getId() > $afterId));
            });
        $open = true;
        $attempts = [];
        $nickRepo->method('save')->willReturnCallback(static function (RegisteredNick $nick) use (&$open, &$attempts): void {
            $attempts[] = $nick->getNickname();
            if ('Poison' === $nick->getNickname()) {
                $open = false;

                throw new RuntimeException('flush failed');
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
        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $this->createStub(ServiceDebugNotifierInterface::class), $logger, $this->clock(), $this->createStub(NickServEventPublisher::class), self::SERVER_NAME, 3600, $em);

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
            'Maintenance [nickserv.unsuspend_expired_suspensions]: failed to auto-unsuspend nick Poison (id 1); the EntityManager is closed, stopping this batch.',
            'Maintenance [nickserv.unsuspend_expired_suspensions]: failed to auto-unsuspend nick Poison (id 1); the EntityManager is closed, stopping this batch.',
            'Maintenance [nickserv.unsuspend_expired_suspensions]: failed to auto-unsuspend nick Poison (id 1) with the EntityManager closed 3 times in a row; skipping it until the next sweep and stopping this batch.',
        ], $errors);
    }

    #[Test]
    public function theClosedEntityManagerAttemptsRestartAfterTheSuspensionIsProcessed(): void
    {
        $nicks = [$this->createNickWithId('Flaky', 1), $this->createNickWithId('Healthy', 2)];
        $pages = [];
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findExpiredSuspensions')
            ->willReturnCallback(static function (DateTimeImmutable $now, int $limit, int $afterId) use ($nicks, &$pages): array {
                $pages[] = $afterId;

                return array_values(array_filter($nicks, static fn (RegisteredNick $nick): bool => $nick->getId() > $afterId));
            });
        $open = true;
        $flakyAttempts = 0;
        $nickRepo->method('save')->willReturnCallback(static function (RegisteredNick $nick) use (&$open, &$flakyAttempts): void {
            if ('Flaky' === $nick->getNickname() && 3 !== ++$flakyAttempts) {
                $open = false;

                throw new RuntimeException('lock wait timeout');
            }
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $task = new UnsuspendExpiredSuspensionsTask($nickRepo, $this->createStub(ServiceDebugNotifierInterface::class), new NullLogger(), $this->clock(), $this->createStub(NickServEventPublisher::class), self::SERVER_NAME, 3600, $em);

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
    public function aFailureAfterTheUnsuspendWasSavedIsLoggedAsUnsuspendedAndTheBatchContinues(): void
    {
        $first = $this->createNickWithId('First', 1);
        $first->suspend('Ban', new DateTimeImmutable('-1 hour'));
        $second = $this->createNickWithId('Second', 2);
        $second->suspend('Ban', new DateTimeImmutable('-1 hour'));
        $failure = new RuntimeException('debug channel unavailable');
        $saved = [];
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findExpiredSuspensions')->willReturn([$first, $second]);
        $nickRepo->method('save')->willReturnCallback(static function (RegisteredNick $nick) use (&$saved): void {
            $saved[] = $nick->getNickname();
        });
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $debugNotifier->method('log')->willReturnCallback(static function (string $operator, string $command, string $target) use ($failure): void {
            if ('First' === $target) {
                throw $failure;
            }
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Maintenance [nickserv.unsuspend_expired_suspensions]: auto-unsuspended nick First (id 1), but a post-unsuspend step failed.',
            ['exception' => $failure],
        );
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('detach');

        new UnsuspendExpiredSuspensionsTask($nickRepo, $debugNotifier, $logger, $this->clock(), $this->createStub(NickServEventPublisher::class), self::SERVER_NAME, 3600, $em)->run();

        self::assertSame(['First', 'Second'], $saved);
        self::assertFalse($first->isSuspended());
    }

    private function createNickWithId(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', $nickname . '@example.com', 'en', new DateTimeImmutable('+1 hour'), new DateTimeImmutable());
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($nick, $id);

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
