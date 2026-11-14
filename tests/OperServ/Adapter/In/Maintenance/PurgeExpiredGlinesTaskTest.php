<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\OperServ\Adapter\In\Maintenance\PurgeExpiredGlinesTask;
use App\OperServ\Application\Port\Out\GlineEntry;
use App\OperServ\Application\Port\Out\GlineRepository;
use App\OperServ\Application\PublishedEvent\GlineRemovedEvent;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

use function count;

#[CoversClass(PurgeExpiredGlinesTask::class)]
#[CoversClass(GlineRemovedEvent::class)]
final class PurgeExpiredGlinesTaskTest extends TestCase
{
    private const string SERVER_NAME = 'test-server.example.com';

    #[Test]
    public function getNameReturnsOperservPurgeExpiredGlines(): void
    {
        $glineRepo = $this->createStub(GlineRepository::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new PurgeExpiredGlinesTask($glineRepo, $debugNotifier, $this->createStub(EventBusInterface::class), new NullLogger(), self::SERVER_NAME, 3600);

        self::assertSame('operserv.purge_expired_glines', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsConfiguredValue(): void
    {
        $glineRepo = $this->createStub(GlineRepository::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new PurgeExpiredGlinesTask($glineRepo, $debugNotifier, $this->createStub(EventBusInterface::class), new NullLogger(), self::SERVER_NAME, 7200);

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns360(): void
    {
        $glineRepo = $this->createStub(GlineRepository::class);
        $debugNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $task = new PurgeExpiredGlinesTask($glineRepo, $debugNotifier, $this->createStub(EventBusInterface::class), new NullLogger(), self::SERVER_NAME, 3600);

        self::assertSame(360, $task->getOrder());
    }

    #[Test]
    public function runRemovesExpiredGlinesAndLogsToDebug(): void
    {
        $expiredGline1 = new GlineEntry('*@host1.com', null, null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 101);

        $expiredGline2 = new GlineEntry('*@host2.com', null, null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 102);

        $glineRepo = $this->createMock(GlineRepository::class);
        $glineRepo->expects(self::once())->method('findExpiredAt')->willReturn([$expiredGline1, $expiredGline2]);
        $glineRepo->expects(self::never())->method('remove');
        $glineRepo->expects(self::once())->method('deleteByIds')->with([101, 102]);

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::exactly(2))->method('log')
            ->willReturnCallback(static function (string $operator, string $command, string $target, ?string $targetHost, ?string $targetIp, ?string $reason): void {
                self::assertSame(self::SERVER_NAME, $operator);
                self::assertSame('GLINE DEL', $command);
                self::assertSame('expired', $reason);
            });

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::exactly(2))->method('dispatch')
            ->willReturnCallback(static function (object $event): void {
                self::assertInstanceOf(GlineRemovedEvent::class, $event);
            });

        $task = new PurgeExpiredGlinesTask($glineRepo, $debugNotifier, $eventDispatcher, new NullLogger(), self::SERVER_NAME, 3600);
        $task->run();
    }

    #[Test]
    public function runDoesNothingWhenNoExpiredGlines(): void
    {
        $glineRepo = $this->createMock(GlineRepository::class);
        $glineRepo->expects(self::once())->method('findExpiredAt')->willReturn([]);
        $glineRepo->expects(self::never())->method('remove');

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::never())->method('log');

        $task = new PurgeExpiredGlinesTask($glineRepo, $debugNotifier, $this->createStub(EventBusInterface::class), new NullLogger(), self::SERVER_NAME, 3600);
        $task->run();
    }

    #[Test]
    public function rejectsPersistedGlineWithoutIdentifier(): void
    {
        $gline = new GlineEntry('*@host.test', null, null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'));
        $glineRepo = $this->createMock(GlineRepository::class);
        $glineRepo->expects(self::once())->method('findExpiredAt')->willReturn([$gline]);
        $glineRepo->expects(self::never())->method('remove');

        $task = new PurgeExpiredGlinesTask(
            $glineRepo,
            $this->createStub(ServiceDebugNotifierInterface::class),
            $this->createStub(EventBusInterface::class),
            new NullLogger(),
            self::SERVER_NAME,
            3600,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Persisted GLINE entry must have an identifier.');

        $task->run();
    }

    #[Test]
    public function runRemovesCorrectGlineById(): void
    {
        $expiredGline = new GlineEntry('*@isp.com', null, null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), 999);

        $deletedIds = [];
        $glineRepo = $this->createMock(GlineRepository::class);
        $glineRepo->expects(self::once())->method('findExpiredAt')->willReturn([$expiredGline]);
        $glineRepo->expects(self::never())->method('remove');
        $glineRepo->expects(self::once())->method('deleteByIds')
            ->willReturnCallback(static function (array $ids) use (&$deletedIds): int {
                $deletedIds = $ids;

                return count($ids);
            });

        $debugNotifier = $this->createMock(ServiceDebugNotifierInterface::class);
        $debugNotifier->expects(self::once())->method('log')->with(
            self::SERVER_NAME,
            'GLINE DEL',
            '*@isp.com',
            null,
            null,
            'expired',
        );

        $events = [];
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $eventDispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$events): void {
                $events[] = $event;
            });

        $task = new PurgeExpiredGlinesTask($glineRepo, $debugNotifier, $eventDispatcher, new NullLogger(), self::SERVER_NAME, 3600);
        $task->run();

        self::assertSame([999], $deletedIds);
        self::assertCount(1, $events);
        self::assertInstanceOf(GlineRemovedEvent::class, $events[0]);
        self::assertSame(999, $events[0]->glineId);
        self::assertSame('*@isp.com', $events[0]->mask);
        self::assertSame('expired', $events[0]->cause);
    }

    #[Test]
    public function loadsExpiredGlinesInBoundedBatchesAfterTheLastSeenId(): void
    {
        $loads = [];
        $deleted = [];
        $glineRepo = $this->createMock(GlineRepository::class);
        $glineRepo->expects(self::exactly(2))->method('findExpiredAt')
            ->willReturnCallback(function (DateTimeImmutable $at, ?GlineEntry $after, ?int $limit) use (&$loads): array {
                $loads[] = [$after?->id, $limit];

                return null === $after ? [$this->expired(1), $this->expired(2)] : [$this->expired(3)];
            });
        $glineRepo->expects(self::exactly(2))->method('deleteByIds')
            ->willReturnCallback(static function (array $ids) use (&$deleted): int {
                $deleted[] = $ids;

                return count($ids);
            });
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::exactly(3))->method('dispatch');

        $task = new PurgeExpiredGlinesTask($glineRepo, $this->createStub(ServiceDebugNotifierInterface::class), $eventDispatcher, new NullLogger(), self::SERVER_NAME, 3600, 2);
        $task->run();

        self::assertSame([[null, 2], [2, 2]], $loads);
        self::assertSame([[1, 2], [3]], $deleted);
    }

    #[Test]
    public function aGlineThatKeepsFailingIsLoggedAndSkippedWithoutBlockingLaterRows(): void
    {
        $failure = new RuntimeException('network sink rejected the removal');
        $loads = [];
        $deleted = [];
        $glineRepo = $this->createMock(GlineRepository::class);
        $glineRepo->expects(self::exactly(2))->method('findExpiredAt')
            ->willReturnCallback(function (DateTimeImmutable $at, ?GlineEntry $after, ?int $limit) use (&$loads): array {
                $loads[] = [$after?->id, $limit];

                return null === $after ? [$this->expired(1), $this->expired(2)] : [$this->expired(3)];
            });
        $glineRepo->expects(self::exactly(2))->method('deleteByIds')
            ->willReturnCallback(static function (array $ids) use (&$deleted): int {
                $deleted[] = $ids;

                return count($ids);
            });
        $removed = [];
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $eventDispatcher->method('dispatch')
            ->willReturnCallback(static function (object $event) use ($failure, &$removed): void {
                self::assertInstanceOf(GlineRemovedEvent::class, $event);
                if (1 === $event->glineId) {
                    throw $failure;
                }
                $removed[] = $event->glineId;
            });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Maintenance [operserv.purge_expired_glines]: failed to remove expired GLINE *@host1.test (id 1); skipping it until the next cycle.',
            ['exception' => $failure],
        );
        $logger->expects(self::exactly(2))->method('info');

        $task = new PurgeExpiredGlinesTask($glineRepo, $this->createStub(ServiceDebugNotifierInterface::class), $eventDispatcher, $logger, self::SERVER_NAME, 3600, 2);
        $task->run();

        self::assertSame([[null, 2], [2, 2]], $loads);
        self::assertSame([2, 3], $removed);
        self::assertSame([[2], [3]], $deleted);
    }

    private function expired(int $id): GlineEntry
    {
        return new GlineEntry('*@host' . $id . '.test', null, null, new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour'), $id);
    }
}
