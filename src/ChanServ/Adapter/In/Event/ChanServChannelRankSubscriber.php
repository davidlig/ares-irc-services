<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\Application\ChanServ\Event\ChannelSecureEnabledEvent;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanks;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanksHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementTrigger;
use App\ChanServ\Application\UseCase\EnforceRanks\SynchronizeAllChannelRanksHandler;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\Domain\ChanServ\Event\ChannelFounderChangedEvent;
use App\Irc\Application\PublishedEvent\ChannelMemberRankGrantedEvent;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\IrcMessageHandledEvent;
use App\Irc\Application\PublishedEvent\IrcMessageHandlingStartedEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\UserDepartedChannelEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Translates framework/IRC events to the ChanServ rank-enforcement use cases. */
final readonly class ChanServChannelRankSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EnforceChannelRanksHandlerInterface $enforceChannelRanks,
        private SynchronizeAllChannelRanksHandler $synchronizeAllChannelRanks,
        private PendingChannelRankSynchronizations $pending,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            IrcMessageHandlingStartedEvent::class => ['onMessageReceived', 256],
            IrcMessageHandledEvent::class => ['onIrcMessageHandled', -255],
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', 0],
            UserDepartedChannelEvent::class => ['onUserLeftChannel', 0],
            NetworkSynchronizationCompletedEvent::class => ['onNetworkSynchronizationCompleted', 0],
            ChannelSynchronizedEvent::class => ['onChannelSynced', 0],
            ChannelSecureEnabledEvent::class => ['onChannelSecureEnabled', 0],
            ChannelFounderChangedEvent::class => ['onChannelFounderChanged', 0],
            ChannelMemberRankGrantedEvent::class => ['onChannelMemberRankGranted', 0],
        ];
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

    public function onUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        $this->enforceChannelRanks->handle(new EnforceChannelRanks(
            $event->channelName,
            RankEnforcementTrigger::MemberJoined,
            $event->uid,
            joinedRank: $this->initialRank($event->initialRole),
        ));
    }

    public function onUserLeftChannel(UserDepartedChannelEvent $event): void
    {
        $this->enforceChannelRanks->handle(new EnforceChannelRanks(
            $event->channelName,
            RankEnforcementTrigger::MemberLeft,
            $event->uid,
        ));
    }

    public function onNetworkSynchronizationCompleted(): void
    {
        $this->synchronizeAllChannelRanks->handle();
    }

    public function onChannelSynced(ChannelSynchronizedEvent $event): void
    {
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
