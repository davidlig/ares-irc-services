<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Maintenance;

use App\Application\NickServ\Maintenance\PurgeInactiveNicknamesTask;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(PurgeInactiveNicknamesTask::class)]
final class PurgeInactiveNicknamesTaskTest extends TestCase
{
    #[Test]
    public function getNameReturnsTaskName(): void
    {
        $task = new PurgeInactiveNicknamesTask(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(LoggerInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(LoggerInterface::class),
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
        $repo->expects(self::never())->method('delete');
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $task = new PurgeInactiveNicknamesTask(
            $repo,
            $eventDispatcher,
            $this->createStub(LoggerInterface::class),
            3600,
            0,
        );
        $task->run();
    }

    #[Test]
    public function runDispatchesNickDropEventAndDeletesAndLogsForEachInactiveNick(): void
    {
        $lastSeen = new DateTimeImmutable('2024-01-01 12:00:00');
        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getId')->willReturn(42);
        $nick->method('getNickname')->willReturn('OldNick');
        $nick->method('getNicknameLower')->willReturn('oldnick');
        $nick->method('getLastSeenAt')->willReturn($lastSeen);
        $nick->method('getRegisteredAt')->willReturn(new DateTimeImmutable('2023-01-01'));

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findRegisteredInactiveSince')
            ->with(self::callback(static function (DateTimeImmutable $t): bool {
                $expected = (new DateTimeImmutable())->modify('-90 days');

                return $t->format('Y-m-d') === $expected->format('Y-m-d');
            }))
            ->willReturn([$nick]);
        $repo->expects(self::once())->method('delete')->with($nick);

        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $event) use (&$dispatched): bool {
                if ($event instanceof NickDropEvent) {
                    $dispatched[] = $event;

                    return 42 === $event->nickId
                        && 'OldNick' === $event->nickname
                        && 'oldnick' === $event->nicknameLower
                        && 'inactivity' === $event->reason;
                }

                return false;
            }))
            ->willReturnArgument(0);

        $logMessages = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $msg) use (&$logMessages): void {
            $logMessages[] = $msg;
        });

        $task = new PurgeInactiveNicknamesTask(
            $repo,
            $eventDispatcher,
            $logger,
            3600,
            90,
        );
        $task->run();

        self::assertCount(1, $dispatched);
        self::assertCount(1, $logMessages);
        self::assertStringContainsString('deleted nickname OldNick', $logMessages[0]);
        self::assertStringContainsString('inactivity', $logMessages[0]);
    }

    #[Test]
    public function runSkipsNonRegisteredNickInstancesInResult(): void
    {
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findRegisteredInactiveSince')->willReturn([new stdClass()]);
        $repo->expects(self::never())->method('delete');
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $task = new PurgeInactiveNicknamesTask(
            $repo,
            $eventDispatcher,
            $this->createStub(LoggerInterface::class),
            3600,
            90,
        );
        $task->run();
    }
}
