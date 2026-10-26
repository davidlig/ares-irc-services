<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ClearChannel;

use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\UseCase\ClearAccess\ClearChannelAccess;
use App\ChanServ\Application\UseCase\ClearAccess\ClearChannelAccessHandler;
use App\ChanServ\Application\UseCase\ClearAccess\ClearChannelAccessOutcome;
use App\ChanServ\Application\UseCase\ClearAccess\ClearChannelAccessResult;
use App\ChanServ\Application\UseCase\ClearUsers\ClearChannelUsers;
use App\ChanServ\Application\UseCase\ClearUsers\ClearChannelUsersHandler;
use App\ChanServ\Application\UseCase\ClearUsers\ClearChannelUsersOutcome;
use App\ChanServ\Application\UseCase\ClearUsers\ClearChannelUsersResult;
use App\ChanServ\Domain\Entity\ChannelHistory;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ClearChannelAccess::class)]
#[CoversClass(ClearChannelAccessHandler::class)]
#[CoversClass(ClearChannelAccessResult::class)]
#[CoversClass(ClearChannelUsers::class)]
#[CoversClass(ClearChannelUsersHandler::class)]
#[CoversClass(ClearChannelUsersResult::class)]
final class ClearChannelHandlersTest extends TestCase
{
    #[Test]
    public function clearAccessReportsMissingAndEmptyChannels(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $access = $this->createMock(ChannelAccessRepositoryInterface::class);
        $access->expects(self::never())->method('deleteByChannelId');
        $handler = new ClearChannelAccessHandler($channels, $access, $this->historyService());
        self::assertSame(ClearChannelAccessOutcome::ChannelNotRegistered, $handler->handle($this->accessCommand('#Missing'))->outcome);

        $channels = $this->channels();
        $access = $this->createStub(ChannelAccessRepositoryInterface::class);
        self::assertSame(ClearChannelAccessOutcome::AlreadyEmpty, new ClearChannelAccessHandler($channels, $access, $this->historyService())->handle($this->accessCommand('#Channel'))->outcome);
    }

    #[Test]
    public function clearAccessDeletesStoredEntries(): void
    {
        $access = $this->createMock(ChannelAccessRepositoryInterface::class);
        $access->expects(self::once())->method('countByChannel')->with(11)->willReturn(4);
        $access->expects(self::once())->method('deleteByChannelId')->with(11)->willReturn(4);

        $saved = null;
        $history = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $history->expects(self::once())->method('save')->willReturnCallback(static function (ChannelHistory $entry) use (&$saved): void { $saved = $entry; });
        $result = new ClearChannelAccessHandler($this->channels(), $access, new ChannelHistoryService($history))->handle($this->accessCommand('#CHANNEL'));

        self::assertSame(ClearChannelAccessOutcome::Cleared, $result->outcome);
        self::assertSame(4, $result->removedCount);
        self::assertNotNull($saved);
        self::assertSame('CLEARACCESS', $saved->getAction());
        self::assertSame('Founder', $saved->getPerformedBy());
        self::assertNull($saved->getPerformedByNickId());
        self::assertSame([], $saved->getExtraData());
    }

    #[Test]
    public function clearUsersReportsEveryNonClearingOutcome(): void
    {
        $network = $this->createMock(ChanNetworkActions::class);
        $network->expects(self::never())->method('kickFromChannel');
        self::assertSame(
            ClearChannelUsersOutcome::ChannelNotRegistered,
            new ClearChannelUsersHandler($this->createStub(RegisteredChannelRepositoryInterface::class), $network, $this->historyService())
                ->handle($this->usersCommand('#Missing'))->outcome,
        );

        $network = $this->createStub(ChanNetworkActions::class);
        $network->method('isChannelOnNetwork')->willReturnOnConsecutiveCalls(false, true);
        $network->method('getChannelMemberUids')->willReturn([]);
        $history = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $history->expects(self::never())->method('save');
        $handler = new ClearChannelUsersHandler($this->channels(), $network, new ChannelHistoryService($history));
        self::assertSame(ClearChannelUsersOutcome::ChannelNotOnNetwork, $handler->handle($this->usersCommand('#Channel'))->outcome);
        self::assertSame(ClearChannelUsersOutcome::AlreadyEmpty, $handler->handle($this->usersCommand('#Channel'))->outcome);
    }

    #[Test]
    public function clearUsersKicksEveryMember(): void
    {
        $network = $this->createMock(ChanNetworkActions::class);
        $network->method('isChannelOnNetwork')->willReturn(true);
        $network->method('getChannelMemberUids')->willReturn(['UID1', 'UID2']);
        $network->expects(self::exactly(2))->method('kickFromChannel')->willReturnCallback(
            static function (string $channel, string $uid, string $reason): void {
                self::assertSame('#Channel', $channel);
                self::assertContains($uid, ['UID1', 'UID2']);
                self::assertSame('reason', $reason);
            },
        );

        $saved = null;
        $history = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $history->expects(self::once())->method('save')->willReturnCallback(static function (ChannelHistory $entry) use (&$saved): void { $saved = $entry; });
        $result = new ClearChannelUsersHandler($this->channels(), $network, new ChannelHistoryService($history))->handle($this->usersCommand('#Channel'));

        self::assertSame(ClearChannelUsersOutcome::Cleared, $result->outcome);
        self::assertSame(2, $result->kickedCount);
        self::assertNotNull($saved);
        self::assertSame('CLEARUSERS', $saved->getAction());
        self::assertSame('Founder', $saved->getPerformedBy());
        self::assertNull($saved->getPerformedByNickId());
        self::assertSame([], $saved->getExtraData());
    }

    private function channels(): RegisteredChannelRepositoryInterface
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#Channel', 1, 'Description');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 11);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        return $channels;
    }

    private function historyService(): ChannelHistoryService
    {
        return new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class));
    }

    private function accessCommand(string $channelName): ClearChannelAccess
    {
        return new ClearChannelAccess($channelName, 'Founder', new DateTimeImmutable('2026-01-02'));
    }

    private function usersCommand(string $channelName): ClearChannelUsers
    {
        return new ClearChannelUsers($channelName, 'reason', 'Founder', new DateTimeImmutable('2026-01-02'));
    }
}
