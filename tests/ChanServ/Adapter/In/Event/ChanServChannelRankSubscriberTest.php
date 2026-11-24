<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServBurstChannelSnapshotSubscriber;
use App\ChanServ\Adapter\In\Event\ChanServChannelRankSubscriber;
use App\ChanServ\Adapter\In\Event\DelinkDepartureRankScope;
use App\ChanServ\Adapter\In\Event\PendingChannelRankSynchronizations;
use App\ChanServ\Application\Model\BurstRankEventScope;
use App\ChanServ\Application\Model\ChannelRankNetworkState;
use App\ChanServ\Application\Model\ChannelRankPolicy;
use App\ChanServ\Application\Port\Out\ChannelRankNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelRankPolicyRepository;
use App\ChanServ\Application\Port\Out\RegisteredChannelBurstSnapshot;
use App\ChanServ\Application\PublishedEvent\ChannelFounderChangedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelSecureEnabledEvent;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanks;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanksHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementOutcome;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementResult;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementTrigger;
use App\ChanServ\Domain\ValueObject\ChannelLevelSet;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\In\Event\PublishedIrcEventBridge;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Application\Port\In\BurstCompletePort;
use App\Irc\Application\PublishedEvent\ChannelMemberRankGrantedEvent;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\IrcMessageHandledEvent;
use App\Irc\Application\PublishedEvent\IrcMessageHandlingStartedEvent;
use App\Irc\Application\PublishedEvent\NetworkConnectionEstablishedEvent;
use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\RemoteBurstEndedEvent;
use App\Irc\Application\PublishedEvent\ServerDelinkFinishedEvent;
use App\Irc\Application\PublishedEvent\ServerDelinkStartedEvent;
use App\Irc\Application\PublishedEvent\UserDepartedChannelEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use App\Tests\NickServ\Support\NegotiatedCaseMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_map;
use function count;

#[CoversClass(ChanServChannelRankSubscriber::class)]
#[CoversClass(DelinkDepartureRankScope::class)]
final class ChanServChannelRankSubscriberTest extends TestCase
{
    #[Test]
    public function itSubscribesToTheExactEventContractAndPriorities(): void
    {
        self::assertSame([
            IrcMessageHandlingStartedEvent::class => ['onMessageReceived', 256],
            IrcMessageHandledEvent::class => ['onIrcMessageHandled', -255],
            NetworkConnectionEstablishedEvent::class => ['onNetworkConnectionEstablished', 0],
            NetworkConnectionLostEvent::class => ['onRemoteBurstEnded', 0],
            RemoteBurstEndedEvent::class => ['onRemoteBurstEnded', 0],
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', 0],
            UserDepartedChannelEvent::class => ['onUserLeftChannel', 0],
            ChannelSynchronizedEvent::class => ['onChannelSynced', 0],
            ChannelSecureEnabledEvent::class => ['onChannelSecureEnabled', 0],
            ChannelFounderChangedEvent::class => ['onChannelFounderChanged', 0],
            ChannelMemberRankGrantedEvent::class => ['onChannelMemberRankGranted', 0],
            ServerDelinkStartedEvent::class => ['onServerDelinkStarted', 0],
            ServerDelinkFinishedEvent::class => ['onServerDelinkFinished', 0],
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
        $subscriber = $this->subscriber($handler);

        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent('001AAAA', '#CasePreserved', 'admin', true));
        $subscriber->onUserLeftChannel(new UserDepartedChannelEvent('001BBBB', '#Departed'));
        $subscriber->onChannelSynced(new ChannelSynchronizedEvent('#Synced'));

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
        self::assertTrue($commands[0]->reconcileOnJoin);
    }

    #[Test]
    public function departuresDuringBurstReconciliationUseTheCurrentSnapshot(): void
    {
        $handler = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $handler->expects(self::once())->method('handleKnownPolicyAndNetwork')->willReturn(new RankEnforcementResult(RankEnforcementOutcome::Applied, activityTouched: true));
        $scope = new BurstRankEventScope(NegotiatedCaseMapping::chanServ());
        $scope->begin();
        $scope->setChannel(new ChannelRankPolicy(1, '#test', 10, false, false, ChannelLevelSet::defaults(), []), new ChannelRankNetworkState('#test', [], []));
        $burst = $this->createStub(BurstCompletePort::class);
        $subscriber = new ChanServChannelRankSubscriber($handler, new PendingChannelRankSynchronizations(), $burst, $scope, $this->createStub(RegisteredChannelBurstSnapshot::class), $this->idleDelinkScope());
        $subscriber->onUserLeftChannel(new UserDepartedChannelEvent('U1', '#test'));
        self::assertTrue($scope->needsActivity('#test'));
    }

    #[Test]
    public function departuresWithoutCurrentBurstPolicyDoNotQueryRanks(): void
    {
        $handler = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $handler->expects(self::never())->method('handleKnownPolicyAndNetwork');
        $scope = new BurstRankEventScope(NegotiatedCaseMapping::chanServ());
        $scope->begin();
        $subscriber = new ChanServChannelRankSubscriber(
            $handler,
            new PendingChannelRankSynchronizations(),
            $this->createStub(BurstCompletePort::class),
            $scope,
            $this->createStub(RegisteredChannelBurstSnapshot::class),
            $this->idleDelinkScope(),
        );
        $subscriber->onUserLeftChannel(new UserDepartedChannelEvent('U1', '#forbidden'));
        self::assertFalse($scope->needsActivity('#forbidden'));
    }

    #[Test]
    public function departuresDuringAServerDelinkLoadEachChannelOnce(): void
    {
        $test = new ChannelRankPolicy(1, '#test', 10, false, false, ChannelLevelSet::defaults(), []);
        $other = new ChannelRankPolicy(2, '#other', 10, false, false, ChannelLevelSet::defaults(), []);
        $policies = $this->createMock(ChannelRankPolicyRepository::class);
        $policies->expects(self::exactly(3))->method('findByName')->willReturnCallback(static fn (string $name): ?ChannelRankPolicy => match ($name) {
            '#test' => $test,
            '#other' => $other,
            default => null,
        });
        $networkStates = [];
        $network = $this->createMock(ChannelRankNetworkQuery::class);
        $network->expects(self::never())->method('findChannel');
        $network->expects(self::exactly(3))->method('findMember')->willReturnCallback(static function (string $name, string $uid) use (&$networkStates): ChannelRankNetworkState {
            $networkStates[] = [$name, $uid];

            return new ChannelRankNetworkState($name, [], []);
        });
        $known = [];
        $handler = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (EnforceChannelRanks $command): bool => '#test' === $command->channelName
                && RankEnforcementTrigger::MemberLeft === $command->trigger
                && 'U4' === $command->uid,
        ))->willReturn(new RankEnforcementResult(RankEnforcementOutcome::NoChanges));
        $handler->expects(self::exactly(3))->method('handleKnownPolicyAndNetwork')->willReturnCallback(
            static function (EnforceChannelRanks $command, ChannelRankPolicy $policy, ?ChannelRankNetworkState $state, bool $deferActivity) use (&$known): RankEnforcementResult {
                $known[] = [$command->channelName, $command->trigger, $command->uid, $policy->name, $state?->name, $deferActivity];

                return new RankEnforcementResult(RankEnforcementOutcome::Applied, activityTouched: true);
            },
        );
        $burst = $this->createStub(BurstCompletePort::class);
        $burst->method('isComplete')->willReturn(true);
        $subscriber = new ChanServChannelRankSubscriber(
            $handler,
            new PendingChannelRankSynchronizations(),
            $burst,
            new BurstRankEventScope(NegotiatedCaseMapping::chanServ()),
            $this->createStub(RegisteredChannelBurstSnapshot::class),
            new DelinkDepartureRankScope($policies, $network),
        );

        $subscriber->onServerDelinkStarted();
        $subscriber->onUserLeftChannel(new UserDepartedChannelEvent('U1', '#test'));
        $subscriber->onUserLeftChannel(new UserDepartedChannelEvent('U1', '#unregistered'));
        $subscriber->onUserLeftChannel(new UserDepartedChannelEvent('U2', '#test'));
        $subscriber->onUserLeftChannel(new UserDepartedChannelEvent('U3', '#other'));
        $subscriber->onServerDelinkFinished();
        $subscriber->onUserLeftChannel(new UserDepartedChannelEvent('U4', '#test'));

        self::assertSame([
            ['#test', RankEnforcementTrigger::MemberLeft, 'U1', '#test', '#test', false],
            ['#test', RankEnforcementTrigger::MemberLeft, 'U2', '#test', '#test', false],
            ['#other', RankEnforcementTrigger::MemberLeft, 'U3', '#other', '#other', false],
        ], $known);
        self::assertSame([['#test', 'U1'], ['#test', 'U2'], ['#other', 'U3']], $networkStates);
    }

    #[Test]
    public function burstChannelActivityIsRecordedOnlyForRegisteredNames(): void
    {
        $handler = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $burst = $this->createStub(BurstCompletePort::class);
        $burst->method('isComplete')->willReturn(false);
        $snapshot = $this->createStub(RegisteredChannelBurstSnapshot::class);
        $snapshot->method('contains')->willReturnMap([['#registered', true], ['#unknown', false]]);
        $scope = new BurstRankEventScope(NegotiatedCaseMapping::chanServ());
        $subscriber = new ChanServChannelRankSubscriber($handler, new PendingChannelRankSynchronizations(), $burst, $scope, $snapshot, $this->idleDelinkScope());
        $subscriber->onChannelSynced(new ChannelSynchronizedEvent('#registered'));
        $subscriber->onChannelSynced(new ChannelSynchronizedEvent('#unknown'));
        self::assertTrue($scope->needsActivity('#registered'));
        self::assertFalse($scope->needsActivity('#unknown'));
    }

    #[Test]
    public function itDefersChannelSynchronizationRankEnforcementDuringBurst(): void
    {
        $handler = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $burst = $this->createStub(BurstCompletePort::class);
        $burst->method('isComplete')->willReturn(false);
        $subscriber = new ChanServChannelRankSubscriber(
            $handler,
            new PendingChannelRankSynchronizations(),
            $burst,
            new BurstRankEventScope(NegotiatedCaseMapping::chanServ()),
            $this->createStub(RegisteredChannelBurstSnapshot::class),
            $this->idleDelinkScope(),
        );

        $subscriber->onChannelSynced(new ChannelSynchronizedEvent('#burst'));
    }

    #[Test]
    public function itDefersContinuationJoinRankEnforcementDuringBurstToTheEndOfBurstPass(): void
    {
        $handler = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $handler->expects(self::never())->method('handleKnownPolicyAndNetwork');
        $burst = $this->createStub(BurstCompletePort::class);
        $burst->method('isComplete')->willReturn(false);
        $snapshot = $this->createStub(RegisteredChannelBurstSnapshot::class);
        $snapshot->method('contains')->willReturnMap([['#registered', true], ['#unknown', false]]);
        $scope = new BurstRankEventScope(NegotiatedCaseMapping::chanServ());
        $subscriber = new ChanServChannelRankSubscriber(
            $handler,
            new PendingChannelRankSynchronizations(),
            $burst,
            $scope,
            $snapshot,
            $this->idleDelinkScope(),
        );

        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent('001AAAA', '#registered', 'op', true));
        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent('001BBBB', '#unknown', null, true));

        self::assertTrue($scope->needsActivity('#registered'));
        self::assertFalse($scope->needsActivity('#unknown'));
    }

    /**
     * InspIRCd marks the local burst complete once Ares sends its own burst, before the remote
     * burst arrives; the open remote-burst window still defers ranks to the end-of-burst pass.
     */
    #[Test]
    public function itDefersRankEnforcementWhileTheRemoteBurstIsOpenEvenAfterTheLocalBurst(): void
    {
        $handler = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $burst = $this->createStub(BurstCompletePort::class);
        $burst->method('isComplete')->willReturn(true);
        $snapshot = $this->createStub(RegisteredChannelBurstSnapshot::class);
        $snapshot->method('contains')->willReturnMap([['#registered', true], ['#unknown', false]]);
        $scope = new BurstRankEventScope(NegotiatedCaseMapping::chanServ());
        $subscriber = new ChanServChannelRankSubscriber(
            $handler,
            new PendingChannelRankSynchronizations(),
            $burst,
            $scope,
            $snapshot,
            $this->idleDelinkScope(),
        );
        $subscriber->onNetworkConnectionEstablished();

        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent('0A1AAAAAA', '#registered', 'op', true));
        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent('0A1BBBBBB', '#unknown', null, true));
        $subscriber->onChannelSynced(new ChannelSynchronizedEvent('#registered'));

        self::assertTrue($scope->needsActivity('#registered'));
        self::assertFalse($scope->needsActivity('#unknown'));
    }

    #[Test]
    public function itEnforcesRanksOnJoinOnceTheLocalBurstIsCompleteAndNoRemoteBurstIsOpen(): void
    {
        $commands = [];
        $handler = $this->createStub(EnforceChannelRanksHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(static function (EnforceChannelRanks $command) use (&$commands): RankEnforcementResult {
            $commands[] = $command;

            return new RankEnforcementResult(RankEnforcementOutcome::NoChanges);
        });
        $burst = $this->createStub(BurstCompletePort::class);
        $burst->method('isComplete')->willReturn(true);
        $snapshot = $this->createStub(RegisteredChannelBurstSnapshot::class);
        $snapshot->method('contains')->willReturn(null);
        $subscriber = new ChanServChannelRankSubscriber(
            $handler,
            new PendingChannelRankSynchronizations(),
            $burst,
            new BurstRankEventScope(NegotiatedCaseMapping::chanServ()),
            $snapshot,
            $this->idleDelinkScope(),
        );

        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent('0A1AAAAAA', '#live', 'op', true));

        self::assertCount(1, $commands);
        self::assertSame('#live', $commands[0]->channelName);
        self::assertSame(RankEnforcementTrigger::MemberJoined, $commands[0]->trigger);
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

    /**
     * The remote-burst window closes before any other end-of-sync listener runs, so a listener that
     * throws (and stops propagation before the burst snapshot closes) cannot leave join and sync rank
     * enforcement disabled until the next reconnect.
     */
    #[Test]
    public function aFailingEndOfSyncListenerDoesNotLeaveRankEnforcementDisabled(): void
    {
        $recorder = new class {
            /** @var list<EnforceChannelRanks> */
            public array $commands = [];

            public function count(): int
            {
                return count($this->commands);
            }
        };
        $handler = $this->createStub(EnforceChannelRanksHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(static function (EnforceChannelRanks $command) use ($recorder): RankEnforcementResult {
            $recorder->commands[] = $command;

            return new RankEnforcementResult(RankEnforcementOutcome::NoChanges);
        });
        $burst = $this->createStub(BurstCompletePort::class);
        $burst->method('isComplete')->willReturn(true);
        $snapshot = new class implements RegisteredChannelBurstSnapshot {
            private bool $open = false;

            public function beginBurst(): void
            {
                $this->open = true;
            }

            public function endBurst(): void
            {
                $this->open = false;
            }

            public function contains(string $channelName): ?bool
            {
                return $this->open ? '#registered' === $channelName : null;
            }
        };
        $scope = new BurstRankEventScope(NegotiatedCaseMapping::chanServ());
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PublishedIrcEventBridge($dispatcher));
        $dispatcher->addSubscriber(new ChanServChannelRankSubscriber($handler, new PendingChannelRankSynchronizations(), $burst, $scope, $snapshot, $this->idleDelinkScope()));
        $dispatcher->addSubscriber(new ChanServBurstChannelSnapshotSubscriber($snapshot, $scope));
        $dispatcher->addListener(NetworkSynchronizationCompletedEvent::class, static function (): never {
            throw new \RuntimeException('end-of-burst pass failed');
        }, 10);

        $dispatcher->dispatch(new NetworkConnectionEstablishedEvent());
        $dispatcher->dispatch(new UserJoinedChannelEvent('0A1AAAAAA', '#registered', 'op', true));
        self::assertSame(0, $recorder->count(), 'Joins during the remote burst are deferred.');
        try {
            $dispatcher->dispatch(new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '0A1'));
            self::fail('The end-of-sync listener failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('end-of-burst pass failed', $exception->getMessage());
        }
        self::assertTrue($snapshot->contains('#registered'), 'The failure stops propagation before the snapshot closes.');

        $dispatcher->dispatch(new UserJoinedChannelEvent('0A1BBBBBB', '#registered', 'op', true));
        $dispatcher->dispatch(new ChannelSynchronizedEvent('#registered'));

        $commands = $recorder->commands;
        self::assertCount(2, $commands);
        self::assertSame(RankEnforcementTrigger::MemberJoined, $commands[0]->trigger);
        self::assertSame('0A1BBBBBB', $commands[0]->uid);
        self::assertSame(RankEnforcementTrigger::ChannelSynchronized, $commands[1]->trigger);
    }

    /**
     * An end-of-sync adapter listener that runs before the completion is published (the IRCops debug
     * channel protection, the UDB store initialization or the UDB channel sync) can throw, and the
     * published completion is then never dispatched. The window still closes, so the next join and
     * channel sync enforce ranks without waiting for a reconnect.
     */
    #[Test]
    public function aFailingEarlierEndOfSyncAdapterListenerDoesNotLeaveRankEnforcementDisabled(): void
    {
        $recorder = new class {
            /** @var list<EnforceChannelRanks> */
            public array $commands = [];

            public function count(): int
            {
                return count($this->commands);
            }
        };
        $handler = $this->createStub(EnforceChannelRanksHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(static function (EnforceChannelRanks $command) use ($recorder): RankEnforcementResult {
            $recorder->commands[] = $command;

            return new RankEnforcementResult(RankEnforcementOutcome::NoChanges);
        });
        $burst = $this->createStub(BurstCompletePort::class);
        $burst->method('isComplete')->willReturn(true);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PublishedIrcEventBridge($dispatcher));
        $dispatcher->addSubscriber(new ChanServChannelRankSubscriber(
            $handler,
            new PendingChannelRankSynchronizations(),
            $burst,
            new BurstRankEventScope(NegotiatedCaseMapping::chanServ()),
            $this->createStub(RegisteredChannelBurstSnapshot::class),
            $this->idleDelinkScope(),
        ));
        $completions = new \ArrayObject();
        $dispatcher->addListener(NetworkSynchronizationCompletedEvent::class, static function (NetworkSynchronizationCompletedEvent $event) use ($completions): void {
            $completions->append($event);
        });
        $dispatcher->addListener(NetworkSyncCompleteEvent::class, static function (): never {
            throw new \RuntimeException('end-of-sync adapter listener failed');
        }, 15);

        $dispatcher->dispatch(new NetworkConnectionEstablishedEvent());
        $dispatcher->dispatch(new UserJoinedChannelEvent('0A1AAAAAA', '#live', 'op', true));
        self::assertSame(0, $recorder->count(), 'Joins during the remote burst are deferred.');
        try {
            $dispatcher->dispatch(new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '0A1'));
            self::fail('The end-of-sync adapter listener failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('end-of-sync adapter listener failed', $exception->getMessage());
        }
        self::assertCount(0, $completions, 'The failure stops propagation before the completion is published.');

        $dispatcher->dispatch(new UserJoinedChannelEvent('0A1BBBBBB', '#live', 'op', true));
        $dispatcher->dispatch(new ChannelSynchronizedEvent('#live'));

        $commands = $recorder->commands;
        self::assertCount(2, $commands);
        self::assertSame(RankEnforcementTrigger::MemberJoined, $commands[0]->trigger);
        self::assertSame('0A1BBBBBB', $commands[0]->uid);
        self::assertSame(RankEnforcementTrigger::ChannelSynchronized, $commands[1]->trigger);
    }

    #[Test]
    public function aLostConnectionClosesTheRemoteBurstWindow(): void
    {
        $handler = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (EnforceChannelRanks $command): bool => '#live' === $command->channelName
                && RankEnforcementTrigger::MemberJoined === $command->trigger,
        ))->willReturn(new RankEnforcementResult(RankEnforcementOutcome::NoChanges));
        $subscriber = $this->subscriber($handler);

        $subscriber->onNetworkConnectionEstablished();
        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent('0A1AAAAAA', '#live', 'op', true));
        $subscriber->onRemoteBurstEnded();
        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent('0A1BBBBBB', '#live', 'op', true));
    }

    private function subscriber(
        EnforceChannelRanksHandlerInterface $handler,
    ): ChanServChannelRankSubscriber {
        $burst = $this->createStub(BurstCompletePort::class);
        $burst->method('isComplete')->willReturn(true);

        return new ChanServChannelRankSubscriber(
            $handler,
            new PendingChannelRankSynchronizations(),
            $burst,
            new BurstRankEventScope(NegotiatedCaseMapping::chanServ()),
            $this->createStub(RegisteredChannelBurstSnapshot::class),
            $this->idleDelinkScope(),
        );
    }

    private function idleDelinkScope(): DelinkDepartureRankScope
    {
        return new DelinkDepartureRankScope(
            $this->createStub(ChannelRankPolicyRepository::class),
            $this->createStub(ChannelRankNetworkQuery::class),
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
            new \DateTimeImmutable('2026-01-02 03:04:05'),
        );
    }
}
