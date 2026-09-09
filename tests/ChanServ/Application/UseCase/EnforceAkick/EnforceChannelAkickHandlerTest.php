<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\EnforceAkick;

use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Application\Model\ChannelEntryNetworkState;
use App\ChanServ\Application\Port\Out\AkickActions;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkick;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandler;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceAkick\SynchronizeAllChannelAkicks;
use App\ChanServ\Application\UseCase\EnforceAkick\SynchronizeAllChannelAkicksHandler;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Policy\AkickMatchPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelEntryMember::class)]
#[CoversClass(ChannelEntryNetworkState::class)]
#[CoversClass(EnforceChannelAkick::class)]
#[CoversClass(EnforceChannelAkickHandler::class)]
#[CoversClass(SynchronizeAllChannelAkicks::class)]
#[CoversClass(SynchronizeAllChannelAkicksHandler::class)]
final class EnforceChannelAkickHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-07 12:00:00 UTC');
    }

    #[Test]
    public function joinSkipsUnavailableBlockedAndMissingMembers(): void
    {
        $blocked = $this->channel(blocked: true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturnOnConsecutiveCalls(null, $blocked, $this->channel());
        $network = $this->createMock(ChannelEntryNetworkQuery::class);
        $network->expects(self::once())->method('findMember')->with('missing')->willReturn(null);
        $handler = $this->handler($channels, $this->createStub(ChannelAkickRepositoryInterface::class), $network, $this->createStub(AkickActions::class));

        self::assertSame(0, $handler->handle(new EnforceChannelAkick('#Unavailable', 'missing', $this->now)));
        self::assertSame(0, $handler->handle(new EnforceChannelAkick('#Blocked', 'missing', $this->now)));
        self::assertSame(0, $handler->handle(new EnforceChannelAkick('#MissingUser', 'missing', $this->now)));
    }

    #[Test]
    public function firstCurrentMatchIsEnforcedWithFallbackAndOperatorsAreExempt(): void
    {
        $channel = $this->channel();
        $expired = ChannelAkick::create(new DateTimeImmutable(), 1, 2, '*!*@example.test', 'expired', new DateTimeImmutable('2026-09-07 11:59:59 UTC'));
        $first = ChannelAkick::create(new DateTimeImmutable(), 1, 2, 'nick!*@example.test');
        $later = ChannelAkick::create(new DateTimeImmutable(), 1, 2, 'nick!*@*', 'later');
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$expired, $first, $later]);
        $actions = $this->createMock(AkickActions::class);
        $actions->expects(self::once())->method('banAndKick')->with(
            '#Test',
            '001A',
            'nick!*@example.test',
            'AKICK: nick!*@example.test',
        );
        $handler = $this->handler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $akicks,
            $this->createStub(ChannelEntryNetworkQuery::class),
            $actions,
        );

        self::assertSame(1, $handler->handleKnownChannel($channel, [
            $this->member('001A', 'nick!ident@example.test'),
            $this->member('001B', 'oper!ident@example.test', operator: true),
            $this->member('001C', 'safe!ident@safe.test'),
        ], $this->now));
    }

    #[Test]
    public function exactExpiryInstantStillMatchesAndExplicitReasonIsPreserved(): void
    {
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([
            ChannelAkick::create(new DateTimeImmutable(), 1, 2, '*!*@example.test', 'reason', $this->now),
        ]);
        $actions = $this->createMock(AkickActions::class);
        $actions->expects(self::once())->method('banAndKick')->with('#Test', '001A', '*!*@example.test', 'reason');

        self::assertSame(1, $this->handler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $akicks,
            $this->createStub(ChannelEntryNetworkQuery::class),
            $actions,
        )->handleKnownChannel($this->channel(), [$this->member('001A', 'nick!ident@example.test')], $this->now));
    }

    #[Test]
    public function joinLoadsMemberAndUsesCanonicalRegisteredChannelName(): void
    {
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('findByChannelName')->with('#mixed')->willReturn($this->channel());
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findMember')->willReturn($this->member('001A', 'nick!ident@example.test'));
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([ChannelAkick::create(new DateTimeImmutable(), 1, 2, '*!*@example.test')]);
        $actions = $this->createMock(AkickActions::class);
        $actions->expects(self::once())->method('banAndKick')->with('#Test', '001A', '*!*@example.test', 'AKICK: *!*@example.test');

        self::assertSame(1, $this->handler($channels, $akicks, $network, $actions)->handle(
            new EnforceChannelAkick('#MiXeD', '001A', $this->now),
        ));
    }

    #[Test]
    public function knownBlockedChannelDoesNotLoadRules(): void
    {
        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->expects(self::never())->method('listByChannel');

        self::assertSame(0, $this->handler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $akicks,
            $this->createStub(ChannelEntryNetworkQuery::class),
            $this->createStub(AkickActions::class),
        )->handleKnownChannel($this->channel(blocked: true), [], $this->now));
    }

    #[Test]
    public function fullSynchronizationSkipsBlockedAndUnavailableChannelsAndSumsEffects(): void
    {
        $active = $this->channel(name: '#Active');
        $missing = $this->channel(name: '#Missing');
        $blocked = $this->channel(name: '#Blocked', blocked: true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('listAll')->willReturn([$blocked, $missing, $active]);
        $state = new ChannelEntryNetworkState('#Active', [$this->member('001A', 'nick!ident@example.test')]);
        $network = $this->createMock(ChannelEntryNetworkQuery::class);
        $network->expects(self::exactly(2))->method('findChannel')->willReturnMap([
            ['#Missing', null],
            ['#Active', $state],
        ]);
        $enforcer = $this->createMock(EnforceChannelAkickHandlerInterface::class);
        $enforcer->expects(self::once())->method('handleKnownChannel')->with($active, $state->members, $this->now)->willReturn(2);

        self::assertSame(2, new SynchronizeAllChannelAkicksHandler($channels, $network, $enforcer)->handle(
            new SynchronizeAllChannelAkicks($this->now),
        ));
    }

    private function handler(
        RegisteredChannelRepositoryInterface $channels,
        ChannelAkickRepositoryInterface $akicks,
        ChannelEntryNetworkQuery $network,
        AkickActions $actions,
    ): EnforceChannelAkickHandler {
        return new EnforceChannelAkickHandler($channels, $akicks, $network, $actions, new AkickMatchPolicy());
    }

    private function channel(string $name = '#Test', bool $blocked = false): RegisteredChannel
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn($name);
        $channel->method('isBlocked')->willReturn($blocked);

        return $channel;
    }

    private function member(string $uid, string $mask, bool $operator = false): ChannelEntryMember
    {
        return new ChannelEntryMember($uid, 'nick', $mask, true, $operator, false);
    }
}
