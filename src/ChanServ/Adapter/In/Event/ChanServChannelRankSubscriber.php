<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Model\BurstRankEventScope;
use App\ChanServ\Application\Port\Out\RegisteredChannelBurstSnapshot;
use App\ChanServ\Application\PublishedEvent\ChannelFounderChangedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelSecureEnabledEvent;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanks;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanksHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementTrigger;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\Irc\Application\Port\In\BurstCompletePort;
use App\Irc\Application\PublishedEvent\ChannelMemberRankGrantedEvent;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\IrcMessageHandledEvent;
use App\Irc\Application\PublishedEvent\IrcMessageHandlingStartedEvent;
use App\Irc\Application\PublishedEvent\NetworkConnectionEstablishedEvent;
use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\ServerDelinkFinishedEvent;
use App\Irc\Application\PublishedEvent\ServerDelinkStartedEvent;
use App\Irc\Application\PublishedEvent\UserDepartedChannelEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Translates framework/IRC events to the ChanServ rank-enforcement use cases. */
final readonly class ChanServChannelRankSubscriber implements EventSubscriberInterface
{
    /**
     * Runs before every other end-of-sync listener (the highest is the ChanServ end-of-burst pass at
     * 10), so the window closes even when a later listener throws and stops propagation.
     */
    private const int REMOTE_BURST_END_PRIORITY = 2048;

    private RemoteBurstWindow $remoteBurst;

    public function __construct(
        private EnforceChannelRanksHandlerInterface $enforceChannelRanks,
        private PendingChannelRankSynchronizations $pending,
        private BurstCompletePort $burstComplete,
        private BurstRankEventScope $burstRanks,
        private RegisteredChannelBurstSnapshot $burstChannels,
        private DelinkDepartureRankScope $delinkDepartures,
    ) {
        $this->remoteBurst = new RemoteBurstWindow();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            IrcMessageHandlingStartedEvent::class => ['onMessageReceived', 256],
            IrcMessageHandledEvent::class => ['onIrcMessageHandled', -255],
            NetworkConnectionEstablishedEvent::class => ['onNetworkConnectionEstablished', 0],
            NetworkConnectionLostEvent::class => ['onRemoteBurstEnded', 0],
            NetworkSynchronizationCompletedEvent::class => ['onRemoteBurstEnded', self::REMOTE_BURST_END_PRIORITY],
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', 0],
            UserDepartedChannelEvent::class => ['onUserLeftChannel', 0],
            ChannelSynchronizedEvent::class => ['onChannelSynced', 0],
            ChannelSecureEnabledEvent::class => ['onChannelSecureEnabled', 0],
            ChannelFounderChangedEvent::class => ['onChannelFounderChanged', 0],
            ChannelMemberRankGrantedEvent::class => ['onChannelMemberRankGranted', 0],
            ServerDelinkStartedEvent::class => ['onServerDelinkStarted', 0],
            ServerDelinkFinishedEvent::class => ['onServerDelinkFinished', 0],
        ];
    }

    public function onNetworkConnectionEstablished(): void
    {
        $this->remoteBurst->open();
    }

    public function onRemoteBurstEnded(): void
    {
        $this->remoteBurst->close();
    }

    public function onServerDelinkStarted(): void
    {
        $this->delinkDepartures->begin();
    }

    public function onServerDelinkFinished(): void
    {
        $this->delinkDepartures->end();
    }

    public function onMessageReceived(): void
    {
        $this->pending->beginMessage();
    }

    public function onIrcMessageHandled(): void
    {
        foreach ($this->pending->releaseMessageStartSnapshot() as $channelName) {
            $this->enforceChannelRanks->handle(new EnforceChannelRanks(
                $channelName,
                RankEnforcementTrigger::NetworkSynchronized,
            ));
        }
    }

    /**
     * Joins received before network synchronization completes (continuation SJOIN/FJOIN lines of
     * the burst) are reconciled by the end-of-burst pass, after AKICK and NOJOIN.
     */
    public function onUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        if ($this->duringBurst()) {
            $this->markBurstActivity($event->channelName);

            return;
        }

        $this->enforceChannelRanks->handle(new EnforceChannelRanks(
            $event->channelName,
            RankEnforcementTrigger::MemberJoined,
            $event->uid,
            joinedRank: $this->initialRank($event->initialRole),
            reconcileOnJoin: $event->reconcileRanks,
        ));
    }

    public function onUserLeftChannel(UserDepartedChannelEvent $event): void
    {
        if ($this->burstRanks->active()) {
            $snapshot = $this->burstRanks->channel($event->channelName);
            if (null !== $snapshot) {
                [$policy, $network] = $snapshot;
                $result = $this->enforceChannelRanks->handleKnownPolicyAndNetwork(
                    new EnforceChannelRanks($event->channelName, RankEnforcementTrigger::MemberLeft, $event->uid),
                    $policy,
                    $network,
                    true,
                );
                if ($result->activityTouched) {
                    $this->burstRanks->markActivity($event->channelName);
                }
            }

            return;
        }

        $command = new EnforceChannelRanks($event->channelName, RankEnforcementTrigger::MemberLeft, $event->uid);
        if ($this->delinkDepartures->active()) {
            $this->onDelinkDeparture($command);

            return;
        }

        $this->enforceChannelRanks->handle($command);
    }

    public function onChannelSynced(ChannelSynchronizedEvent $event): void
    {
        if ($this->duringBurst()) {
            $this->markBurstActivity($event->channelName);

            return;
        }

        $this->enforceChannelRanks->handle(new EnforceChannelRanks(
            $event->channelName,
            RankEnforcementTrigger::ChannelSynchronized,
        ));
    }

    public function onChannelSecureEnabled(ChannelSecureEnabledEvent $event): void
    {
        $this->pending->schedule($event->channelName);
    }

    public function onChannelFounderChanged(ChannelFounderChangedEvent $event): void
    {
        $this->pending->schedule($event->channelName);
    }

    public function onChannelMemberRankGranted(ChannelMemberRankGrantedEvent $event): void
    {
        $rank = $this->initialRank($event->rank);
        if (null === $rank) {
            return;
        }

        $this->enforceChannelRanks->handle(new EnforceChannelRanks(
            $event->channelName,
            RankEnforcementTrigger::LiveRankGranted,
            $event->uid,
            $rank,
        ));
    }

    /**
     * A server delink departs every split member from each of its channels; the channel is loaded
     * once for the whole split and each departure is evaluated against that snapshot, recording the
     * same activity a single departure would.
     */
    private function onDelinkDeparture(EnforceChannelRanks $command): void
    {
        $snapshot = $this->delinkDepartures->channel($command->channelName);
        if (null === $snapshot) {
            return;
        }

        [$policy, $network] = $snapshot;
        $this->enforceChannelRanks->handleKnownPolicyAndNetwork($command, $policy, $network);
    }

    /**
     * The remote burst is open from link establishment until network synchronization starts to
     * complete. InspIRCd reports the local burst complete before the remote burst arrives, so both
     * signals are checked to keep the three protocols aligned.
     */
    private function duringBurst(): bool
    {
        return !$this->burstComplete->isComplete() || $this->remoteBurst->isOpen();
    }

    private function markBurstActivity(string $channelName): void
    {
        if (false !== $this->burstChannels->contains($channelName)) {
            $this->burstRanks->markActivity($channelName);
        }
    }

    private function initialRank(?string $role): ?ChannelRank
    {
        return match ($role) {
            'owner' => ChannelRank::Owner,
            'admin' => ChannelRank::Administrator,
            'op' => ChannelRank::Operator,
            'halfop' => ChannelRank::HalfOperator,
            'voice' => ChannelRank::Voice,
            default => null,
        };
    }
}
