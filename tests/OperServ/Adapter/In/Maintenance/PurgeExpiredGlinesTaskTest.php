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
use Psr\Log\NullLogger;

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
        $glineRepo->expects(self::exactly(2))->method('remove');

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

        $removed = [];
        $glineRepo = $this->createStub(GlineRepository::class);
        $glineRepo->method('findExpiredAt')->willReturn([$expiredGline]);
        $glineRepo->method('remove')
            ->willReturnCallback(static function (GlineEntry $gline) use (&$removed): void {
                $removed[] = $gline;
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

        self::assertCount(1, $removed);
        self::assertCount(1, $events);
        self::assertInstanceOf(GlineRemovedEvent::class, $events[0]);
        self::assertSame(999, $events[0]->glineId);
        self::assertSame('*@isp.com', $events[0]->mask);
        self::assertSame('expired', $events[0]->cause);
    }
}
