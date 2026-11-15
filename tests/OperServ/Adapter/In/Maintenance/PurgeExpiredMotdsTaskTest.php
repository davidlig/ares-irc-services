<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\OperServ\Adapter\In\Maintenance\PurgeExpiredMotdsTask;
use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\Port\Out\MotdEntry;
use App\OperServ\Application\Port\Out\MotdRepository;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function is_string;
use function str_contains;

#[CoversClass(PurgeExpiredMotdsTask::class)]
final class PurgeExpiredMotdsTaskTest extends TestCase
{
    #[Test]
    public function getNameReturnsOperservPurgeExpiredMotds(): void
    {
        $task = $this->createTask();

        self::assertSame('operserv.purge_expired_motds', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsConfiguredValue(): void
    {
        $task = $this->createTask(intervalSeconds: 7200);

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns365(): void
    {
        $task = $this->createTask();

        self::assertSame(365, $task->getOrder());
    }

    #[Test]
    public function itRequiresTheMotdCounterFlushSoFinalizedShownCountsAreCurrent(): void
    {
        $task = $this->createTask();

        self::assertSame(['operserv.flush_motd_counters'], $task->getPrerequisiteTaskNames());
    }

    #[Test]
    public function runRemovesExpiredMotdsAndNotifiesDebug(): void
    {
        $expired = new MotdEntry(1, 'Expired', 'Bot1', MessageDelivery::Interactive, true, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 1);

        $motdRepository = $this->createMock(MotdRepository::class);
        $motdRepository->expects(self::once())->method('findExpiredAt')->willReturn([$expired]);
        $motdRepository->expects(self::never())->method('remove');
        $motdRepository->expects(self::once())->method('deleteByIds')->with([1]);

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::once())->method('notify')
            ->with(self::stringContains('MOTD message'));

        $task = $this->createTask(motdRepository: $motdRepository, debugNotifier: $debugNotifier);
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenNoExpiredMotds(): void
    {
        $motdRepository = $this->createMock(MotdRepository::class);
        $motdRepository->expects(self::once())->method('findExpiredAt')->willReturn([]);
        $motdRepository->expects(self::never())->method('remove');
        $motdRepository->expects(self::never())->method('deleteByIds');

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::never())->method('notify');

        $task = $this->createTask(motdRepository: $motdRepository, debugNotifier: $debugNotifier);
        $task->run();
    }

    #[Test]
    public function loadsExpiredMotdsInBoundedBatchesAfterTheLastSeenId(): void
    {
        $loads = [];
        $deleted = [];
        $motdRepository = $this->createMock(MotdRepository::class);
        $motdRepository->expects(self::exactly(2))->method('findExpiredAt')
            ->willReturnCallback(function (DateTimeImmutable $at, ?MotdEntry $after, ?int $limit) use (&$loads): array {
                $loads[] = [$after?->id, $limit];

                return null === $after ? [$this->expired(1), $this->expired(2)] : [$this->expired(3)];
            });
        $motdRepository->expects(self::exactly(2))->method('deleteByIds')
            ->willReturnCallback(static function (array $ids) use (&$deleted): int {
                $deleted[] = $ids;

                return count($ids);
            });
        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::exactly(3))->method('notify');

        $this->createTask(motdRepository: $motdRepository, debugNotifier: $debugNotifier, batchSize: 2)->run();

        self::assertSame([[null, 2], [2, 2]], $loads);
        self::assertSame([[1, 2], [3]], $deleted);
    }

    #[Test]
    public function aFailingDebugNoticeIsLoggedOnItsOwnAndTheExpiredMotdIsStillRemoved(): void
    {
        $failure = new RuntimeException('debug channel unavailable');
        $deleted = [];
        $motdRepository = $this->createMock(MotdRepository::class);
        $motdRepository->expects(self::once())->method('findExpiredAt')->willReturn([$this->expired(1), $this->expired(2)]);
        $motdRepository->expects(self::once())->method('deleteByIds')
            ->willReturnCallback(static function (array $ids) use (&$deleted): int {
                $deleted[] = $ids;

                return count($ids);
            });
        $notified = [];
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $debugNotifier->method('notify')->willReturnCallback(static function (string $message) use ($failure, &$notified): void {
            if (str_contains($message, '#1 ')) {
                throw $failure;
            }
            $notified[] = $message;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->expects(self::once())->method('warning')->with(
            'Maintenance [operserv.purge_expired_motds]: could not post the finalized notice for expired MOTD id 1 to the debug channel; removing it anyway.',
            ['exception' => $failure],
        );
        $logger->expects(self::exactly(2))->method('info');

        $this->createTask(motdRepository: $motdRepository, debugNotifier: $debugNotifier, batchSize: 100, logger: $logger)->run();

        self::assertCount(1, $notified);
        self::assertStringContainsString('#2 ', $notified[0]);
        self::assertSame([[1, 2]], $deleted);
    }

    /**
     * A mass expiry must not block the event loop: one tick handles at most maxBatchesPerCycle
     * batches and the next tick continues from the keyset cursor; a short page ends the sweep.
     */
    #[Test]
    public function oneTickHandlesAtMostTheBatchCapAndTheNextTickContinues(): void
    {
        $state = new PurgeExpiredMotdsTestState([1, 2, 3, 4, 5, 6, 7]);
        $motdRepository = $this->createStub(MotdRepository::class);
        $motdRepository->method('findExpiredAt')->willReturnCallback(
            function (DateTimeImmutable $at, ?MotdEntry $after, ?int $limit) use ($state): array {
                $state->loads[] = [$after?->id, $limit];
                $page = array_values(array_filter($state->ids, static fn (int $id): bool => $id > ($after->id ?? 0)));

                return array_map($this->expired(...), array_slice($page, 0, $limit));
            },
        );
        $motdRepository->method('deleteByIds')->willReturnCallback(static function (array $ids) use ($state): int {
            $state->ids = array_values(array_filter($state->ids, static fn (int $id): bool => !in_array($id, $ids, true)));

            return count($ids);
        });
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $debugNotifier->method('notify')->willReturnCallback(static function () use ($state): void {
            ++$state->handled;
        });
        $task = $this->createTask(motdRepository: $motdRepository, debugNotifier: $debugNotifier, batchSize: 2, maxBatchesPerCycle: 2);

        $task->run();
        self::assertSweep($state, [[null, 2], [2, 2]], [5, 6, 7], 4);

        $task->run();
        self::assertSweep($state, [[null, 2], [2, 2], [4, 2], [6, 2]], [], 7);

        // The short page above ended the sweep, so the next one restarts from the beginning.
        $state->ids = [8];
        $task->run();
        self::assertSweep($state, [[null, 2], [2, 2], [4, 2], [6, 2], [null, 2]], [], 8);
    }

    /**
     * @param list<array{?int, ?int}> $loads
     * @param list<int>               $ids
     */
    private static function assertSweep(PurgeExpiredMotdsTestState $state, array $loads, array $ids, int $handled): void
    {
        self::assertSame($loads, $state->loads);
        self::assertSame($ids, $state->ids);
        self::assertSame($handled, $state->handled);
    }

    private function expired(int $id): MotdEntry
    {
        return new MotdEntry($id, 'Expired ' . $id, 'Bot1', MessageDelivery::Interactive, true, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 0);
    }

    private function createTask(
        ?MotdRepository $motdRepository = null,
        ?ServiceDebugNotifierInterface $debugNotifier = null,
        int $intervalSeconds = 3600,
        int $batchSize = 100,
        ?LoggerInterface $logger = null,
        int $maxBatchesPerCycle = PurgeExpiredMotdsTask::DEFAULT_MAX_BATCHES,
    ): PurgeExpiredMotdsTask {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
            if ('motd.list.shown_count' === $id) {
                $count = $params['%count%'] ?? null;
                if (!is_string($count)) {
                    throw new LogicException('Expected a string MOTD count.');
                }

                return 'shown ' . $count . ' times';
            }

            if ('motd.debug.finalized' === $id) {
                $idValue = $params['%id%'] ?? null;
                $type = $params['%type%'] ?? null;
                $message = $params['%message%'] ?? null;
                $date = $params['%date%'] ?? null;
                $shownCount = $params['%shown_count%'] ?? null;
                if (!is_string($idValue) || !is_string($type) || !is_string($message) || !is_string($date) || !is_string($shownCount)) {
                    throw new LogicException('Expected string MOTD translation parameters.');
                }

                return 'MOTD message #' . $idValue . ' has ended: [' . $type . '] ' . $message . ' | ' . $date . ' | ' . $shownCount;
            }

            return $id;
        });

        return new PurgeExpiredMotdsTask(
            $motdRepository ?? $this->createStub(MotdRepository::class),
            $debugNotifier ?? $this->createStub(ServiceDebugNotifierInterface::class),
            $translator,
            $logger ?? new NullLogger(),
            'en',
            'UTC',
            $intervalSeconds,
            $batchSize,
            $maxBatchesPerCycle,
        );
    }
}

final class PurgeExpiredMotdsTestState
{
    /** @var list<array{?int, ?int}> */
    public array $loads = [];

    /** Expired rows whose removal was announced (event or debug notice). */
    public int $handled = 0;

    /** @param list<int> $ids expired MOTD ids still stored */
    public function __construct(public array $ids) {}
}
