<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Adapter\Network\Event\ChannelJoinReceivedEvent;
use App\Irc\Adapter\Network\Event\ChannelKickReceivedEvent;
use App\Irc\Adapter\Network\Event\ChannelListModeReceivedEvent;
use App\Irc\Adapter\Network\Event\ChannelModeReceivedEvent;
use App\Irc\Adapter\Network\Event\ChannelPartReceivedEvent;
use App\Irc\Adapter\Network\Event\ChannelTopicReceivedEvent;
use App\Irc\Adapter\Network\Event\UserHostReceivedEvent;
use App\Irc\Adapter\Network\Event\UserMetadataReceivedEvent;
use App\Irc\Adapter\Network\Event\UserModeReceivedEvent;
use App\Irc\Adapter\Network\Event\UserNickChangeReceivedEvent;
use App\Irc\Adapter\Network\Event\UserQuitReceivedEvent;
use App\Irc\Application\Port\In\NickChangePreservesIdentificationInterface;
use App\Irc\Application\Port\In\SkipIdentifiedModeStripRegistry;
use App\Irc\Domain\Event\ChannelModesChangedEvent;
use App\Irc\Domain\Event\ChannelSyncedEvent;
use App\Irc\Domain\Event\ChannelTopicChangedEvent;
use App\Irc\Domain\Event\UserHostChangedEvent;
use App\Irc\Domain\Event\UserJoinedChannelEvent;
use App\Irc\Domain\Event\UserLeftChannelEvent;
use App\Irc\Domain\Event\UserModeChangedEvent;
use App\Irc\Domain\Event\UserNickChangedEvent;
use App\Irc\Domain\Event\UserQuitNetworkEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\Repository\ChannelRepositoryInterface;
use App\Irc\Domain\Repository\NetworkUserRepositoryInterface;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use App\Shared\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Shared\Application\Port\ActiveConnectionHolderInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function preg_match;

/**
 * Listens to raw protocol events, resolves entities via repos, and dispatches domain events.
 * Single place that uses ChannelRepository and NetworkUserRepository for protocol→state.
 * Adapters no longer inject repos; they only parse and dispatch raw events.
 */
final readonly class NetworkEventEnricher implements EventSubscriberInterface, ApplyOutgoingChannelModesApplicatorInterface
{
    public function __construct(
        private ChannelRepositoryInterface $channelRepository,
        private NetworkUserRepositoryInterface $userRepository,
        private EventDispatcherInterface $eventDispatcher,
        private SkipIdentifiedModeStripRegistry $skipIdentifiedModeStripRegistry,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private ActiveConnectionHolderInterface $connectionHolder,
        private LoggerInterface $logger = new NullLogger(),
        private ChannelModeStateSynchronizer $channelModeStateSynchronizer = new ChannelModeStateSynchronizer(),
    ) {}

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserQuitReceivedEvent::class => ['onUserQuitReceived', 256],
            UserNickChangeReceivedEvent::class => ['onUserNickChangeReceived', 256],
            ChannelPartReceivedEvent::class => ['onChannelPartReceived', 256],
            ChannelKickReceivedEvent::class => ['onChannelKickReceived', 256],
            ChannelJoinReceivedEvent::class => ['onChannelJoinReceived', 256],
            ChannelModeReceivedEvent::class => ['onChannelModeReceived', 256],
            ChannelListModeReceivedEvent::class => ['onChannelListModeReceived', 256],
            ChannelTopicReceivedEvent::class => ['onChannelTopicReceived', 256],
            UserModeReceivedEvent::class => ['onUserModeReceived', 256],
            UserHostReceivedEvent::class => ['onUserHostReceived', 256],
            UserMetadataReceivedEvent::class => ['onUserMetadataReceived', 256],
        ];
    }

    public function onUserQuitReceived(UserQuitReceivedEvent $event): void
    {
        $user = $this->resolveUser($event->sourceId);
        if (null === $user) {
            return;
        }

        foreach ($user->getChannelNames() as $channelNameStr) {
            $this->eventDispatcher->dispatch(new UserLeftChannelEvent(
                $user->uid,
                $user->getNick(),
                new ChannelName($channelNameStr),
                $event->reason,
                false,
            ));
        }

        $this->userRepository->removeByUid($user->uid);
        $this->eventDispatcher->dispatch(new UserQuitNetworkEvent(
            uid: $user->uid,
            nick: $user->getNick(),
            reason: $event->reason,
            ident: $user->ident->value,
            displayHost: $user->getDisplayHost(),
            hostname: $user->hostname,
            ipBase64: $user->ipBase64,
        ));
    }

    public function onUserNickChangeReceived(UserNickChangeReceivedEvent $event): void
    {
        $user = $this->resolveUser($event->sourceId);
        if (null === $user) {
            return;
        }

        try {
            $newNick = new Nick($event->newNickStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $oldNick = $user->getNick();

        // Do not strip +r when:
        // 1. Services originated this nick change (e.g. restore from Guest nick).
        // 2. The active protocol module handles authentication server-side (e.g. UDB),
        //    so the IRCd itself manages +r on nick changes with nick:password.
        if (!$this->skipIdentifiedModeStripRegistry->peek($user->uid->value)
            && !$this->protocolPreservesIdentificationOnNickChange()) {
            $this->eventDispatcher->dispatch(new UserModeChangedEvent($user->uid, '-r'));
        }

        $this->eventDispatcher->dispatch(new UserNickChangedEvent($user->uid, $oldNick, $newNick));
    }

    public function onChannelPartReceived(ChannelPartReceivedEvent $event): void
    {
        $user = $this->resolveUser($event->sourceId);
        if (null === $user) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserLeftChannelEvent(
            $user->uid,
            $user->getNick(),
            $event->channelName,
            $event->reason,
            $event->wasKicked,
        ));
    }

    public function onChannelKickReceived(ChannelKickReceivedEvent $event): void
    {
        $target = $this->resolveUser($event->targetId);
        if (null === $target) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserLeftChannelEvent(
            $target->uid,
            $target->getNick(),
            $event->channelName,
            $event->reason,
            wasKicked: true,
        ));
    }

    public function onChannelJoinReceived(ChannelJoinReceivedEvent $event): void
    {
        $channel = $this->channelRepository->findByName($event->channelName);
        $isNewChannel = null === $channel;

        if ($isNewChannel) {
            $channel = new Channel(
                name: $event->channelName,
                modes: $event->modeStr,
                createdAt: new DateTimeImmutable('@' . ($event->timestamp > 0 ? $event->timestamp : time())),
            );
        } else {
            if ($event->timestamp > 0) {
                $channel->updateCreatedAt(new DateTimeImmutable('@' . $event->timestamp));
            }
            if ('' !== $event->modeStr) {
                $channel->updateModes($event->modeStr);
            }
        }

        $this->channelModeStateSynchronizer->applyInitialParams(
            $channel,
            $event->modeStr,
            $event->modeParams,
            $this->modeSupportProvider->getSupport(),
        );

        foreach ($event->listModes['b'] ?? [] as $mask) {
            $channel->addBan($mask);
        }
        foreach ($event->listModes['e'] ?? [] as $mask) {
            $channel->addExempt($mask);
        }
        foreach ($event->listModes['I'] ?? [] as $mask) {
            $channel->addInviteException($mask);
        }

        $memberCountBefore = $channel->getMemberCount();
        $joinedUids = [];
        foreach ($event->members as $member) {
            $prefixLetters = $member['prefixLetters'] ?? null;
            $channel->syncMember($member['uid'], $member['role'], $prefixLetters);
            $joinedUids[] = $member['uid'];
        }

        $channelSetupApplicable = $isNewChannel || (0 === $memberCountBefore);

        if ($isNewChannel) {
            $this->channelRepository->save($channel);
            $this->eventDispatcher->dispatch(new ChannelSyncedEvent($channel, $channelSetupApplicable));
        } else {
            $this->channelRepository->save($channel);
            $this->eventDispatcher->dispatch(new ChannelSyncedEvent($channel, $channelSetupApplicable));
            foreach ($joinedUids as $uid) {
                $member = $channel->getMember($uid);
                $role = null !== $member ? $member->role : ChannelMemberRole::None;
                $this->eventDispatcher->dispatch(new UserJoinedChannelEvent($uid, $event->channelName, $role));
            }
        }
    }

    public function onChannelModeReceived(ChannelModeReceivedEvent $event): void
    {
        $channel = $this->channelRepository->findByName($event->channelName);
        if (null === $channel) {
            return;
        }

        $this->channelModeStateSynchronizer->applyReceived(
            $channel,
            $event->modeStr,
            array_values($event->modeParams),
            $this->modeSupportProvider->getSupport(),
            $this->resolveUser(...),
        );

        $this->channelRepository->save($channel);
        $this->eventDispatcher->dispatch(new ChannelModesChangedEvent($channel));
    }

    public function onChannelListModeReceived(ChannelListModeReceivedEvent $event): void
    {
        $channel = $this->channelRepository->findByName($event->channelName);
        if (null === $channel) {
            return;
        }

        $this->channelModeStateSynchronizer->applyListSnapshot($channel, $event->modeChar, array_values($event->params));

        $this->channelRepository->save($channel);
        $this->eventDispatcher->dispatch(new ChannelModesChangedEvent($channel));
    }

    public function onChannelTopicReceived(ChannelTopicReceivedEvent $event): void
    {
        $channel = $this->channelRepository->findByName($event->channelName);
        if (null === $channel) {
            return;
        }

        $channel->updateTopic($event->topic);
        $this->channelRepository->save($channel);
        $this->eventDispatcher->dispatch(new ChannelTopicChangedEvent($channel));
    }

    /**
     * Applies outgoing channel MODE (sent by us) to Core state so ChannelLookup
     * returns up-to-date modes. Call after sending MODE so SET MLOCK ON etc. see the correct state.
     * Does NOT dispatch ChannelModesChangedEvent — MLOCK enforcement runs at the
     * appropriate time via ChannelSyncedEvent, NetworkSyncCompleteEvent, or inbound
     * mode changes, avoiding premature MLOCK enforcement that would reorder rank
     * removal (-o) after channel mode application (+M).
     *
     * @param array<int, string> $params Params in wire order for modes that take a param
     */
    public function applyOutgoingChannelModes(string $channelName, string $modeStr, array $params = []): void
    {
        try {
            $name = new ChannelName($channelName);
        } catch (InvalidArgumentException) {
            return;
        }

        $channel = $this->channelRepository->findByName($name);
        if (null === $channel) {
            return;
        }

        $this->channelModeStateSynchronizer->applyOutgoing(
            $channel,
            $modeStr,
            $params,
            $this->modeSupportProvider->getSupport(),
        );

        $this->channelRepository->save($channel);
    }

    public function onUserMetadataReceived(UserMetadataReceivedEvent $event): void
    {
        $user = $this->userRepository->findByUid(new Uid($event->targetUid));
        if (null === $user) {
            return;
        }

        if ('account' === $event->key || 'accountname' === $event->key || 'accountid' === $event->key) {
            if ('' === $event->value || '*' === $event->value || '0' === $event->value) {
                $this->eventDispatcher->dispatch(new UserModeChangedEvent($user->uid, '-r'));
            } else {
                $this->eventDispatcher->dispatch(new UserModeChangedEvent($user->uid, '+r'));
            }
        }
    }

    public function onUserModeReceived(UserModeReceivedEvent $event): void
    {
        $user = $this->resolveUser($event->sourceId);
        if (null === $user) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserModeChangedEvent($user->uid, $event->modeStr));
    }

    public function onUserHostReceived(UserHostReceivedEvent $event): void
    {
        $user = $this->resolveUser($event->sourceId);
        if (null === $user) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserHostChangedEvent($user->uid, $event->newHost));
    }

    private function resolveUser(string $sourceId): ?NetworkUser
    {
        if (preg_match('/^[0-9][0-9A-Z]{8}$/', $sourceId)) {
            return $this->userRepository->findByUid(new Uid($sourceId));
        }

        try {
            return $this->userRepository->findByNick(new Nick($sourceId));
        } catch (InvalidArgumentException) {
        }

        return null;
    }

    private function protocolPreservesIdentificationOnNickChange(): bool
    {
        $module = $this->connectionHolder->getProtocolModule();

        return $module instanceof NickChangePreservesIdentificationInterface;
    }
}
