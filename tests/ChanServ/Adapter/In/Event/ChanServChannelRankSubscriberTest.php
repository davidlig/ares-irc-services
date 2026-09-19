<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServChannelRankSubscriber;
use App\ChanServ\Adapter\In\Event\PendingChannelRankSynchronizations;
use App\ChanServ\Application\Port\Out\ChannelRankPolicyRepository;
use App\ChanServ\Application\PublishedEvent\ChannelFounderChangedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelSecureEnabledEvent;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanks;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanksHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementOutcome;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementResult;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementTrigger;
use App\ChanServ\Application\UseCase\EnforceRanks\SynchronizeAllChannelRanksHandler;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\Irc\Application\PublishedEvent\ChannelMemberRankGrantedEvent;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\IrcMessageHandledEvent;
use App\Irc\Application\PublishedEvent\IrcMessageHandlingStartedEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\UserDepartedChannelEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServChannelRankSubscriber::class)]
final class ChanServChannelRankSubscriberTest extends TestCase
{
    #[Test]
    public function itSubscribesToTheExactEventContractAndPriorities(): void
    {
        self::assertSame([
            IrcMessageHandlingStartedEvent::class => ['onMessageReceived', 256],
            IrcMessageHandledEvent::class => ['onIrcMessageHandled', -255],
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', 0],
            UserDepartedChannelEvent::class => ['onUserLeftChannel', 0],
            NetworkSynchronizationCompletedEvent::class => ['onNetworkSynchronizationCompleted', 0],
            ChannelSynchronizedEvent::class => ['onChannelSynced', 0],
            ChannelSecureEnabledEvent::class => ['onChannelSecureEnabled', 0],
            ChannelFounderChangedEvent::class => ['onChannelFounderChanged', 0],
            ChannelMemberRankGrantedEvent::class => ['onChannelMemberRankGranted', 0],
        ], ChanServChannelRankSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function itTranslatesMemberAndSynchronizationEventsToSemanticCommands(): void
    {
        $commands = [];
        $handler = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $handler->expects(self::exactly(3))->method('handle')
            ->willReturnCallback(static function (EnforceChannelRanks $command) use (&$commands): RankEnforcementResult {
                $commands[] = $command;

                return new RankEnforcementResult(RankEnforcementOutcome::NoChanges);
            });
        $policies = $this->createMock(ChannelRankPolicyRepository::class);
        $policies->expects(self::once())->method('all')->willReturn([]);
        $subscriber = $this->subscriber($handler, policies: $policies);

        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent('001AAAA', '#CasePreserved', 'admin'));
        $subscriber->onUserLeftChannel(new UserDepartedChannelEvent('001BBBB', '#Departed'));
        $subscriber->onChannelSynced(new ChannelSynchronizedEvent('#Synced'));
        $subscriber->onNetworkSynchronizationCompleted();

        self::assertSame([
            ['#CasePreserved', RankEnforcementTrigger::MemberJoined, '001AAAA', null, ChannelRank::Administrator],
            ['#Departed', RankEnforcementTrigger::MemberLeft, '001BBBB', null, null],
            ['#Synced', RankEnforcementTrigger::ChannelSynchronized, null, null, null],
        ], array_map(
            static fn (EnforceChannelRanks $command): array => [
                $command->channelName,
                $command->trigger,
                $command->uid,
                $command->grantedRank,
                $command->joinedRank,
            ],
            $commands,
        ));
    }

    #[Test]
    #[DataProvider('initialRoles')]
    public function itMapsEverySemanticInitialRole(?string $role, ?ChannelRank $expected): void
    {
        $handler = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (EnforceChannelRanks $command): bool => $expected === $command->joinedRank,
        ))->willReturn(new RankEnforcementResult(RankEnforcementOutcome::NoChanges));

        $this->subscriber($handler)->onUserJoinedChannel(new UserJoinedChannelEvent('001AAAA', '#test', $role));
    }

    /** @return iterable<string, array{?string, ?ChannelRank}> */
    public static function initialRoles(): iterable
    {
        yield 'owner' => ['owner', ChannelRank::Owner];
        yield 'administrator' => ['admin', ChannelRank::Administrator];
        yield 'operator' => ['op', ChannelRank::Operator];
        yield 'half-operator' => ['halfop', ChannelRank::HalfOperator];
        yield 'voice' => ['voice', ChannelRank::Voice];
        yield 'none or unknown' => [null, null];
    }

    #[Test]
    public function secureAndFounderChangesAreCoalescedAndReleasedAfterTheNextMessageBoundary(): void
    {
        $commands = [];
        $handler = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $handler->expects(self::exactly(2))->method('handle')
            ->willReturnCallback(static function (EnforceChannelRanks $command) use (&$commands): RankEnforcementResult {
                $commands[] = $command;

                return new RankEnforcementResult(RankEnforcementOutcome::NoChanges);
            });
        $subscriber = $this->subscriber($handler);

        $subscriber->onChannelSecureEnabled(new ChannelSecureEnabledEvent('#MixedCase'));
        $subscriber->onChannelFounderChanged($this->founderChanged('#MIXEDCASE'));
        $subscriber->onMessageReceived();
        $subscriber->onChannelSecureEnabled(new ChannelSecureEnabledEvent('#Later'));
        $subscriber->onIrcMessageHandled();
        $subscriber->onMessageReceived();
        $subscriber->onIrcMessageHandled();

        self::assertSame([
            ['#mixedcase', RankEnforcementTrigger::NetworkSynchronized],
            ['#later', RankEnforcementTrigger::NetworkSynchronized],
        ], array_map(
            static fn (EnforceChannelRanks $command): array => [$command->channelName, $command->trigger],
            $commands,
        ));
    }

    #[Test]
    public function itTranslatesSemanticLiveRankGrantsAndIgnoresUnknownRanks(): void
    {
        $handler = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (EnforceChannelRanks $command): bool => '#Modes' === $command->channelName
                && '001AAAA' === $command->uid
                && RankEnforcementTrigger::LiveRankGranted === $command->trigger
                && ChannelRank::Operator === $command->grantedRank,
        ))->willReturn(new RankEnforcementResult(RankEnforcementOutcome::NoChanges));
        $subscriber = $this->subscriber($handler);

        $subscriber->onChannelMemberRankGranted(new ChannelMemberRankGrantedEvent('#Modes', '001AAAA', 'op'));
        $subscriber->onChannelMemberRankGranted(new ChannelMemberRankGrantedEvent('#Modes', '001BBBB', 'unknown'));
    }

    private function subscriber(
        EnforceChannelRanksHandlerInterface $handler,
        ?ChannelRankPolicyRepository $policies = null,
    ): ChanServChannelRankSubscriber {
        if (null === $policies) {
            $policyStub = $this->createStub(ChannelRankPolicyRepository::class);
            $policyStub->method('all')->willReturn([]);
            $policies = $policyStub;
        }

        return new ChanServChannelRankSubscriber(
            $handler,
            new SynchronizeAllChannelRanksHandler($policies, $handler),
            new PendingChannelRankSynchronizations(),
        );
    }

    private function founderChanged(string $channelName): ChannelFounderChangedEvent
    {
        return new ChannelFounderChangedEvent(
            1,
            $channelName,
            10,
            20,
            'Oper',
            null,
            '127.0.0.1',
            'host.example',
            new DateTimeImmutable('2026-01-02 03:04:05'),
        );
    }
}
