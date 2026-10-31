<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ManageAkick;

use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Application\Model\ChannelEntryNetworkState;
use App\ChanServ\Application\Port\Out\AkickActions;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelAkickChangedEvent;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkick;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickAction;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickHandler;
use App\ChanServ\Application\UseCase\ManageAkick\ManageChannelAkickOutcome;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Policy\AkickAdditionPolicy;
use App\ChanServ\Domain\Policy\AkickMatchPolicy;
use App\ChanServ\Domain\Policy\AkickProtectionPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ManageChannelAkickHandler::class)]
final class ManageChannelAkickHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    #[Test]
    public function testIncompleteActionInputHasNoSideEffects(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $levels = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levels->expects(self::never())->method('save');
        $levels->expects(self::never())->method('removeAllForChannel');
        $access = $this->createMock(ChannelAccessRepositoryInterface::class);
        $access->expects(self::never())->method('save');
        $access->expects(self::never())->method('remove');
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::never())->method('findAccountByNick');
        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::never())->method('publish');
        $policy = new ChanServAccessHelper($access, $levels);
        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->expects(self::never())->method('save');
        $akicks->expects(self::never())->method('remove');
        $network = $this->createMock(ChannelEntryNetworkQuery::class);
        $network->expects(self::never())->method('synchronizationComplete');
        $actions = $this->createMock(AkickActions::class);
        $actions->expects(self::never())->method('banAndKick');
        $handler = new ManageChannelAkickHandler(
            $channels,
            $akicks,
            $accounts,
            $access,
            $policy,
            new AkickAdditionPolicy(),
            new AkickMatchPolicy(),
            new AkickProtectionPolicy(),
            $network,
            $actions,
            $events,
        );

        foreach ([[null, true], ['', true], ['*!*@example.test', false]] as [$item, $valid]) {
            $result = $handler->handle(new ManageChannelAkick(
                '#test',
                ManageChannelAkickAction::Delete,
                1,
                true,
                'Founder',
                'ip',
                'host',
                $this->now,
                item: $item,
                validRequest: $valid,
            ));
            self::assertSame(ManageChannelAkickOutcome::InvalidRequest, $result->outcome);
        }
    }

    #[Test]
    public function channelNotFoundThrowsException(): void
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn(null);

        $handler = $this->createHandler(channels: $channels);

        $this->expectException(ChannelNotRegisteredException::class);
        $handler->handle(new ManageChannelAkick(
            '#unregistered',
            ManageChannelAkickAction::Clear,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
        ));
    }

    #[Test]
    public function actorNotAuthenticatedReturnsActorNotAuthenticated(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $handler = $this->createHandler(channels: $channels);
        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Clear,
            null,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
        ));

        self::assertSame(ManageChannelAkickOutcome::ActorNotAuthenticated, $result->outcome);
    }

    #[Test]
    public function missingSenderReturnsMissingSender(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $handler = $this->createHandler(channels: $channels);
        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Clear,
            1,
            true,
            null,
            'ip',
            'host',
            $this->now,
        ));

        self::assertSame(ManageChannelAkickOutcome::MissingSender, $result->outcome);
    }

    #[Test]
    public function unknownActionReturnsUnknownAction(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $handler = $this->createHandler(channels: $channels);
        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Unknown,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
        ));

        self::assertSame(ManageChannelAkickOutcome::UnknownAction, $result->outcome);
    }

    #[Test]
    public function clearWhenEmptyReturnsClearEmpty(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([]);
        $akicks->expects(self::never())->method('remove');

        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::never())->method('publish');

        $handler = $this->createHandler(channels: $channels, akicks: $akicks, events: $events);
        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Clear,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
        ));

        self::assertSame(ManageChannelAkickOutcome::ClearEmpty, $result->outcome);
    }

    #[Test]
    public function clearWithEntriesRemovesAllAndPublishesEventAndReturnsCleared(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $akick1 = ChannelAkick::create($this->now, 1, 2, '*!*@one.com');
        $akick2 = ChannelAkick::create($this->now, 1, 2, '*!*@two.com');

        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$akick1, $akick2]);
        $akicks->expects(self::exactly(2))->method('remove');

        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::once())->method('publish')->with(self::callback(
            static fn (object $event): bool => $event instanceof ChannelAkickChangedEvent
                && 1 === $event->channelId
                && 'CLEAR' === $event->action
                && '*' === $event->mask
                && 'Founder' === $event->performedBy,
        ));

        $handler = $this->createHandler(channels: $channels, akicks: $akicks, events: $events);
        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Clear,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
        ));

        self::assertSame(ManageChannelAkickOutcome::Cleared, $result->outcome);
        self::assertSame(2, $result->count);
    }

    #[Test]
    public function viewInvalidRequestReturnsInvalidRequest(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $handler = $this->createHandler(channels: $channels);

        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::View,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
            item: null,
            validRequest: true,
        ));
        self::assertSame(ManageChannelAkickOutcome::InvalidRequest, $result->outcome);

        $result2 = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::View,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
            item: '*!*@test.com',
            validRequest: false,
        ));
        self::assertSame(ManageChannelAkickOutcome::InvalidRequest, $result2->outcome);
    }

    #[Test]
    public function viewNotFoundReturnsEntryNotFound(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('findByChannelAndMask')->willReturn(null);
        $akicks->method('listByChannel')->willReturn([]);

        $handler = $this->createHandler(channels: $channels, akicks: $akicks);

        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::View,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
            item: '*!*@notfound.com',
            validRequest: true,
        ));
        self::assertSame(ManageChannelAkickOutcome::EntryNotFound, $result->outcome);

        $result2 = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::View,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
            item: '99',
            validRequest: true,
        ));
        self::assertSame(ManageChannelAkickOutcome::EntryNotFound, $result2->outcome);

        $result3 = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::View,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
            item: '0',
            validRequest: true,
        ));
        self::assertSame(ManageChannelAkickOutcome::EntryNotFound, $result3->outcome);
    }

    #[Test]
    public function viewByMaskReturnsViewedEntry(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $akick = ChannelAkick::create($this->now, 1, 2, '*!*@test.com', 'Bad user');
        $akick->recordUsage($this->now);

        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('findByChannelAndMask')->willReturn($akick);

        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturn('CreatorNick');

        $handler = $this->createHandler(channels: $channels, akicks: $akicks, accounts: $accounts);

        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::View,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
            item: '*!*@test.com',
            validRequest: true,
        ));

        self::assertSame(ManageChannelAkickOutcome::Viewed, $result->outcome);
        self::assertSame('*!*@test.com', $result->mask);
        self::assertNotNull($result->entry);
        self::assertSame('*!*@test.com', $result->entry->mask);
        self::assertSame('Bad user', $result->entry->reason);
        self::assertSame('CreatorNick', $result->entry->creatorNickname);
        self::assertSame(1, $result->entry->uses);
        self::assertSame($this->now, $result->entry->lastUsedAt);
        self::assertSame($this->now, $result->entry->createdAt);
    }

    #[Test]
    public function viewByNumberReturnsViewedEntry(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $akick1 = ChannelAkick::create($this->now, 1, 2, '*!*@first.com');
        $akick2 = ChannelAkick::create($this->now, 1, 3, '*!*@second.com', 'Second reason');

        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$akick1, $akick2]);

        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturnMap([[2, 'FirstCreator'], [3, 'SecondCreator']]);

        $handler = $this->createHandler(channels: $channels, akicks: $akicks, accounts: $accounts);

        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::View,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
            item: '2',
            validRequest: true,
        ));

        self::assertSame(ManageChannelAkickOutcome::Viewed, $result->outcome);
        self::assertSame('*!*@second.com', $result->mask);
        self::assertNotNull($result->entry);
        self::assertSame('*!*@second.com', $result->entry->mask);
        self::assertSame('Second reason', $result->entry->reason);
        self::assertSame('SecondCreator', $result->entry->creatorNickname);
    }

    #[Test]
    public function checkInvalidRequestReturnsInvalidRequest(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $handler = $this->createHandler(channels: $channels);

        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Check,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
            item: null,
            validRequest: true,
        ));
        self::assertSame(ManageChannelAkickOutcome::InvalidRequest, $result->outcome);

        $result2 = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Check,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
            item: 'Alice',
            validRequest: false,
        ));
        self::assertSame(ManageChannelAkickOutcome::InvalidRequest, $result2->outcome);
    }

    #[Test]
    public function checkUserNotConnectedReturnsCheckUserNotConnected(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findMemberByNick')->willReturn(null);

        $handler = $this->createHandler(channels: $channels, network: $network);

        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Check,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
            item: 'OfflineUser',
            validRequest: true,
        ));

        self::assertSame(ManageChannelAkickOutcome::CheckUserNotConnected, $result->outcome);
        self::assertSame('OfflineUser', $result->targetNickname);
    }

    #[Test]
    public function checkNoMatchReturnsCheckNoMatch(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $member = new ChannelEntryMember('UID1', 'GoodUser', 'GoodUser!user@example.com', true, false, false);
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findMemberByNick')->willReturn($member);

        $akick = ChannelAkick::create($this->now, 1, 2, '*!*@bad.com');
        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$akick]);

        $handler = $this->createHandler(channels: $channels, akicks: $akicks, network: $network);

        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Check,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
            item: 'GoodUser',
            validRequest: true,
        ));

        self::assertSame(ManageChannelAkickOutcome::CheckNoMatch, $result->outcome);
        self::assertSame('GoodUser', $result->targetNickname);
    }

    #[Test]
    public function checkWithMatchesReturnsCheckedAndMatchingEntries(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $member = new ChannelEntryMember('UID1', 'BadUser', 'BadUser!ident@bad.com', true, false, false);
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findMemberByNick')->willReturn($member);

        $akick1 = ChannelAkick::create($this->now, 1, 2, '*!*@bad.com', 'Spammer');
        $akick2 = ChannelAkick::create($this->now, 1, 2, 'BadUser!*@*', 'Abuser');
        $akick3 = ChannelAkick::create($this->now, 1, 2, '*!*@other.com');

        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$akick1, $akick2, $akick3]);

        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturn('Founder');

        $handler = $this->createHandler(channels: $channels, akicks: $akicks, accounts: $accounts, network: $network);

        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Check,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
            item: 'BadUser',
            validRequest: true,
        ));

        self::assertSame(ManageChannelAkickOutcome::Checked, $result->outcome);
        self::assertSame('BadUser', $result->targetNickname);
        self::assertCount(2, $result->entries);
        self::assertSame('*!*@bad.com', $result->entries[0]->mask);
        self::assertSame('Spammer', $result->entries[0]->reason);
        self::assertSame('Founder', $result->entries[0]->creatorNickname);
        self::assertSame(1, $result->entries[0]->entryNumber);
        self::assertSame('BadUser!*@*', $result->entries[1]->mask);
        self::assertSame(2, $result->entries[1]->entryNumber);
    }

    #[Test]
    public function checkWithNonContiguousMatchesPreservesOriginalListIndices(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $member = new ChannelEntryMember('UID1', 'TargetNick', 'TargetNick!ident@special.host', true, false, false);
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findMemberByNick')->willReturn($member);

        $akick1 = ChannelAkick::create($this->now, 1, 2, '*!*@unrelated.com', 'Unrelated');
        $akick2 = ChannelAkick::create($this->now, 1, 2, '*!*@special.host', 'Matched');
        $akick3 = ChannelAkick::create($this->now, 1, 2, '*!*@other.com', 'Other');

        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$akick1, $akick2, $akick3]);

        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturn('Founder');

        $handler = $this->createHandler(channels: $channels, akicks: $akicks, accounts: $accounts, network: $network);

        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Check,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
            item: 'TargetNick',
            validRequest: true,
        ));

        self::assertSame(ManageChannelAkickOutcome::Checked, $result->outcome);
        self::assertCount(1, $result->entries);
        self::assertSame('*!*@special.host', $result->entries[0]->mask);
        self::assertSame(2, $result->entries[0]->entryNumber);
    }

    #[Test]
    public function checkWithoutPrivilegesMatchesOnlyVisibleMaskAndPreventsRealHostInference(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $member = new ChannelEntryMember(
            'UID_VICTIM',
            'Victim',
            'Victim!ident@secret-real-isp.example.net',
            true,
            false,
            false,
            'Victim!ident@victim.cloaked.net',
        );
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findMemberByNick')->willReturn($member);

        // Akick on real host only
        $akickReal = ChannelAkick::create($this->now, 1, 2, '*!*@secret-real-isp.example.net', 'RealHostRule');
        $akickCloaked = ChannelAkick::create($this->now, 1, 2, '*!*@victim.cloaked.net', 'CloakedRule');

        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$akickReal, $akickCloaked]);

        $access = $this->createStub(ChannelAccessRepositoryInterface::class);
        $level = $this->createStub(ChannelLevelRepositoryInterface::class);
        $helper = new ChanServAccessHelper($access, $level);

        $handler = $this->createHandler(channels: $channels, akicks: $akicks, network: $network, access: $access);

        // Non-privileged caller (founderEquivalent: false) cannot match against secret real host
        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Check,
            1,
            false,
            'ChannelOwner',
            'ip',
            'host',
            $this->now,
            item: 'Victim',
            validRequest: true,
        ));

        self::assertSame(ManageChannelAkickOutcome::Checked, $result->outcome);
        self::assertSame('Victim', $result->targetNickname);
        self::assertCount(1, $result->entries);
        self::assertSame('*!*@victim.cloaked.net', $result->entries[0]->mask);

        // If only the real host akick exists, non-privileged caller gets CheckNoMatch
        $akicksSingle = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicksSingle->method('listByChannel')->willReturn([$akickReal]);
        $handlerSingle = $this->createHandler(channels: $channels, akicks: $akicksSingle, network: $network, access: $access);

        $resultNoMatch = $handlerSingle->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Check,
            1,
            false,
            'ChannelOwner',
            'ip',
            'host',
            $this->now,
            item: 'Victim',
            validRequest: true,
        ));
        self::assertSame(ManageChannelAkickOutcome::CheckNoMatch, $resultNoMatch->outcome);
    }

    #[Test]
    public function checkWithPrivilegesMatchesBothRealAndVisibleMasks(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $member = new ChannelEntryMember(
            'UID_VICTIM',
            'Victim',
            'Victim!ident@secret-real-isp.example.net',
            true,
            false,
            false,
            'Victim!ident@victim.cloaked.net',
        );
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findMemberByNick')->willReturn($member);

        $akickReal = ChannelAkick::create($this->now, 1, 2, '*!*@secret-real-isp.example.net', 'RealHostRule');
        $akickCloaked = ChannelAkick::create($this->now, 1, 2, '*!*@victim.cloaked.net', 'CloakedRule');

        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$akickReal, $akickCloaked]);

        $handler = $this->createHandler(channels: $channels, akicks: $akicks, network: $network);

        // Privileged caller (founderEquivalent: true) matches both rules
        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Check,
            1,
            true,
            'IrcopUser',
            'ip',
            'host',
            $this->now,
            item: 'Victim',
            validRequest: true,
        ));

        self::assertSame(ManageChannelAkickOutcome::Checked, $result->outcome);
        self::assertSame('Victim', $result->targetNickname);
        self::assertCount(2, $result->entries);
    }

    #[Test]
    public function listWithSyncCompleteDoesNotEnforceRulesNorMutateUsage(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $akick = ChannelAkick::create($this->now, 1, 2, '*!*@bad.com', 'Spam');
        self::assertSame(0, $akick->getUses());
        self::assertNull($akick->getLastUsedAt());

        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$akick]);
        $akicks->expects(self::never())->method('save');

        $network = $this->createMock(ChannelEntryNetworkQuery::class);
        $network->method('synchronizationComplete')->willReturn(true);
        $network->expects(self::never())->method('findChannel');

        $actions = $this->createMock(AkickActions::class);
        $actions->expects(self::never())->method('banAndKick');

        $handler = $this->createHandler(channels: $channels, akicks: $akicks, network: $network, actions: $actions);

        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::List,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
        ));

        self::assertSame(ManageChannelAkickOutcome::Listed, $result->outcome);
        self::assertCount(1, $result->entries);
        self::assertSame(0, $akick->getUses());
        self::assertNull($akick->getLastUsedAt());
        $entry = $result->entries[0];
        self::assertSame(0, $entry->uses);
        self::assertNull($entry->lastUsedAt);
        self::assertSame(1, $entry->entryNumber);
    }

    #[Test]
    public function enforceWhenNoMatchesReturnsEnforceNoMatch(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([]);
        $akicks->expects(self::never())->method('save');

        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::never())->method('publish');

        $handler = $this->createHandler(channels: $channels, akicks: $akicks, events: $events);

        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Enforce,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
        ));

        self::assertSame(ManageChannelAkickOutcome::EnforceNoMatch, $result->outcome);
        self::assertSame(0, $result->count);
    }

    #[Test]
    public function enforceRulesIgnoresAkickWithInvalidMask(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $invalidAkick = $this->createStub(ChannelAkick::class);
        $invalidAkick->method('getMask')->willReturn('invalid-mask-without-bang-and-at');

        $akicks = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$invalidAkick]);

        $member = new ChannelEntryMember('UID1', 'BadGuy', 'BadGuy!ident@bad.com', true, false, false);
        $channelState = new ChannelEntryNetworkState('#test', [$member]);

        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findChannel')->willReturn($channelState);

        $actions = $this->createMock(AkickActions::class);
        $actions->expects(self::never())->method('banAndKick');

        $handler = $this->createHandler(channels: $channels, akicks: $akicks, network: $network, actions: $actions);

        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Enforce,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
        ));

        self::assertSame(ManageChannelAkickOutcome::EnforceNoMatch, $result->outcome);
        self::assertSame(0, $result->count);
    }

    #[Test]
    public function enforceWithMatchesKicksUsersAndPublishesEventAndReturnsEnforced(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        $akick = ChannelAkick::create($this->now, 1, 2, '*!*@bad.com', 'Spam reason');
        $akicks = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akicks->method('listByChannel')->willReturn([$akick]);
        $akicks->expects(self::once())->method('save')->with($akick);

        $member = new ChannelEntryMember('UID1', 'BadGuy', 'BadGuy!ident@bad.com', true, false, false);
        $channelState = new ChannelEntryNetworkState('#test', [$member]);

        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findChannel')->willReturn($channelState);

        $actions = $this->createMock(AkickActions::class);
        $actions->expects(self::once())->method('banAndKick')->with('#test', 'UID1', '*!*@bad.com', 'Spam reason');

        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::once())->method('publish')->with(self::callback(static function (object $event): bool {
            return $event instanceof ChannelAkickChangedEvent
                && 1 === $event->channelId
                && '#test' === $event->channelName
                && 'ENFORCE' === $event->action
                && 1 === $event->count
                && 'Founder' === $event->performedBy;
        }));

        $handler = $this->createHandler(
            channels: $channels,
            akicks: $akicks,
            network: $network,
            actions: $actions,
            events: $events,
        );

        $result = $handler->handle(new ManageChannelAkick(
            '#test',
            ManageChannelAkickAction::Enforce,
            1,
            true,
            'Founder',
            'ip',
            'host',
            $this->now,
        ));

        self::assertSame(ManageChannelAkickOutcome::Enforced, $result->outcome);
        self::assertSame(1, $result->count);
        self::assertSame(1, $akick->getUses());
        self::assertSame($this->now, $akick->getLastUsedAt());
    }

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-30 12:00:00');
    }

    private function createHandler(
        ?RegisteredChannelRepositoryInterface $channels = null,
        ?ChannelAkickRepositoryInterface $akicks = null,
        ?ChanUserAccountPort $accounts = null,
        ?ChannelAccessRepositoryInterface $access = null,
        ?ChannelEntryNetworkQuery $network = null,
        ?AkickActions $actions = null,
        ?ChanServEventPublisher $events = null,
    ): ManageChannelAkickHandler {
        $accessRepo = $access ?? $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $policy = new ChanServAccessHelper($accessRepo, $levelRepo);

        return new ManageChannelAkickHandler(
            $channels ?? $this->createStub(RegisteredChannelRepositoryInterface::class),
            $akicks ?? $this->createStub(ChannelAkickRepositoryInterface::class),
            $accounts ?? $this->createStub(ChanUserAccountPort::class),
            $accessRepo,
            $policy,
            new AkickAdditionPolicy(),
            new AkickMatchPolicy(),
            new AkickProtectionPolicy(),
            $network ?? $this->createStub(ChannelEntryNetworkQuery::class),
            $actions ?? $this->createStub(AkickActions::class),
            $events ?? $this->createStub(ChanServEventPublisher::class),
        );
    }
}
