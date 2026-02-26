<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network;

use App\Domain\IRC\Event\ChannelModesChangedEvent;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\ChannelTopicChangedEvent;
use App\Domain\IRC\Event\FjoinReceivedEvent;
use App\Domain\IRC\Event\FmodeReceivedEvent;
use App\Domain\IRC\Event\FtopicReceivedEvent;
use App\Domain\IRC\Event\KickReceivedEvent;
use App\Domain\IRC\Event\LmodeReceivedEvent;
use App\Domain\IRC\Event\ModeReceivedEvent;
use App\Domain\IRC\Event\NickChangeReceivedEvent;
use App\Domain\IRC\Event\PartReceivedEvent;
use App\Domain\IRC\Event\QuitReceivedEvent;
use App\Domain\IRC\Event\Umode2ReceivedEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Event\UserLeftChannelEvent;
use App\Domain\IRC\Event\UserModeChangedEvent;
use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\ChannelRepositoryInterface;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\SkipIdentifiedModeStripRegistryInterface;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function count;
use function preg_match;
use function preg_quote;
use function str_replace;
use function str_split;

/**
 * Listens to raw protocol events, resolves entities via repos, and dispatches domain events.
 * Single place that uses ChannelRepository and NetworkUserRepository for protocol→state.
 * Adapters no longer inject repos; they only parse and dispatch raw events.
 */
final readonly class NetworkEventEnricher implements EventSubscriberInterface
{
    public function __construct(
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly NetworkUserRepositoryInterface $userRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SkipIdentifiedModeStripRegistryInterface $skipIdentifiedModeStripRegistry,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            QuitReceivedEvent::class => ['onQuitReceived', 256],
            NickChangeReceivedEvent::class => ['onNickChangeReceived', 256],
            PartReceivedEvent::class => ['onPartReceived', 256],
            KickReceivedEvent::class => ['onKickReceived', 256],
            FjoinReceivedEvent::class => ['onFjoinReceived', 256],
            FmodeReceivedEvent::class => ['onFmodeReceived', 256],
            LmodeReceivedEvent::class => ['onLmodeReceived', 256],
            FtopicReceivedEvent::class => ['onFtopicReceived', 256],
            ModeReceivedEvent::class => ['onModeReceived', 256],
            Umode2ReceivedEvent::class => ['onUmode2Received', 256],
        ];
    }

    public function onQuitReceived(QuitReceivedEvent $event): void
    {
        $user = $this->resolveUser($event->sourceId);
        if (null === $user) {
            return;
        }

        foreach ($user->getChannelNames() as $channelNameStr) {
            $channel = $this->channelRepository->findByName(new ChannelName($channelNameStr));
            if (null !== $channel) {
                $channel->removeMember($user->uid);
                $this->channelRepository->save($channel);
            }
        }

        $this->userRepository->removeByUid($user->uid);
        $this->eventDispatcher->dispatch(new UserQuitNetworkEvent(
            uid: $user->uid,
            nick: $user->getNick(),
            reason: $event->reason,
            ident: $user->ident->value,
            displayHost: $user->getDisplayHost(),
        ));
    }

    public function onNickChangeReceived(NickChangeReceivedEvent $event): void
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

        // Do not strip +r when services originated this nick change (e.g. restore).
        if (!$this->skipIdentifiedModeStripRegistry->peek($user->uid->value)) {
            $this->eventDispatcher->dispatch(new UserModeChangedEvent($user->uid, '-r'));
        }

        $this->eventDispatcher->dispatch(new UserNickChangedEvent($user->uid, $oldNick, $newNick));
    }

    public function onPartReceived(PartReceivedEvent $event): void
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

    public function onKickReceived(KickReceivedEvent $event): void
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

    public function onFjoinReceived(FjoinReceivedEvent $event): void
    {
        $channel = $this->channelRepository->findByName($event->channelName);
        $isNewChannel = null === $channel;

        if ($isNewChannel) {
            $channel = new Channel(
                name: $event->channelName,
                modes: $event->modeStr,
                createdAt: new DateTimeImmutable('@' . $event->timestamp),
            );
        } else {
            if ('' !== $event->modeStr) {
                $channel->updateModes($event->modeStr);
            }
        }

        foreach ($event->listModes['b'] ?? [] as $mask) {
            $channel->addBan($mask);
        }
        foreach ($event->listModes['e'] ?? [] as $mask) {
            $channel->addExempt($mask);
        }
        foreach ($event->listModes['I'] ?? [] as $mask) {
            $channel->addInviteException($mask);
        }

        $joinedUids = [];
        foreach ($event->members as $member) {
            $channel->syncMember($member['uid'], $member['role']);
            $joinedUids[] = $member['uid'];
        }

        if ($isNewChannel) {
            $this->channelRepository->save($channel);
            $this->eventDispatcher->dispatch(new ChannelSyncedEvent($channel));
        } else {
            foreach ($joinedUids as $uid) {
                $member = $channel->getMember($uid);
                $role = $member?->role ?? ChannelMemberRole::None;
                $this->eventDispatcher->dispatch(new UserJoinedChannelEvent($uid, $event->channelName, $role));
            }
            $this->channelRepository->save($channel);
            $this->eventDispatcher->dispatch(new ChannelSyncedEvent($channel));
        }
    }

    public function onFmodeReceived(FmodeReceivedEvent $event): void
    {
        $channel = $this->channelRepository->findByName($event->channelName);
        if (null === $channel) {
            return;
        }

        $current = $channel->getModes();
        $channel->updateModes($this->mergeModeString($current, $event->modeStr));
        $this->channelRepository->save($channel);
        $this->eventDispatcher->dispatch(new ChannelModesChangedEvent($channel));
    }

    public function onLmodeReceived(LmodeReceivedEvent $event): void
    {
        $channel = $this->channelRepository->findByName($event->channelName);
        if (null === $channel) {
            return;
        }

        $params = $event->params;
        for ($i = 0; $i < count($params); $i += 3) {
            $mask = $params[$i] ?? '';
            if ('' === $mask) {
                break;
            }
            if ('b' === $event->modeChar) {
                $channel->addBan($mask);
            } elseif ('e' === $event->modeChar) {
                $channel->addExempt($mask);
            } elseif ('I' === $event->modeChar) {
                $channel->addInviteException($mask);
            }
        }

        $this->channelRepository->save($channel);
        $this->eventDispatcher->dispatch(new ChannelModesChangedEvent($channel));
    }

    public function onFtopicReceived(FtopicReceivedEvent $event): void
    {
        $channel = $this->channelRepository->findByName($event->channelName);
        if (null === $channel) {
            return;
        }

        $channel->updateTopic($event->topic);
        $this->channelRepository->save($channel);
        $this->eventDispatcher->dispatch(new ChannelTopicChangedEvent($channel));
    }

    public function onModeReceived(ModeReceivedEvent $event): void
    {
        $channel = $this->channelRepository->findByName($event->channelName);
        if (null === $channel) {
            return;
        }

        $params = $event->modeParams;
        $paramIdx = 0;
        $adding = true;

        foreach (str_split($event->modeStr) as $char) {
            if ('+' === $char) {
                $adding = true;
                continue;
            }
            if ('-' === $char) {
                $adding = false;
                continue;
            }

            $role = ChannelMemberRole::fromModeLetter($char);
            if (null !== $role) {
                if ($paramIdx >= count($params)) {
                    break;
                }
                $targetId = $params[$paramIdx];
                ++$paramIdx;
                $user = $this->resolveUser($targetId);
                if (null !== $user) {
                    $channel->syncMember($user->uid, $adding ? $role : ChannelMemberRole::None);
                }
                continue;
            }

            if ('b' === $char || 'e' === $char || 'I' === $char) {
                if ($paramIdx >= count($params)) {
                    break;
                }
                $mask = $params[$paramIdx];
                ++$paramIdx;
                if ('b' === $char) {
                    $adding ? $channel->addBan($mask) : $channel->removeBan($mask);
                } elseif ('e' === $char) {
                    $adding ? $channel->addExempt($mask) : $channel->removeExempt($mask);
                } else {
                    $adding ? $channel->addInviteException($mask) : $channel->removeInviteException($mask);
                }
            }
        }

        $this->channelRepository->save($channel);
        $this->eventDispatcher->dispatch(new ChannelModesChangedEvent($channel));
    }

    public function onUmode2Received(Umode2ReceivedEvent $event): void
    {
        $user = $this->resolveUser($event->sourceId);
        if (null === $user) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserModeChangedEvent($user->uid, $event->modeStr));
    }

    private function resolveUser(string $sourceId): ?NetworkUser
    {
        if (preg_match('/^[0-9][0-9A-Z]{8}$/', $sourceId)) {
            try {
                return $this->userRepository->findByUid(new Uid($sourceId));
            } catch (InvalidArgumentException) {
            }
        }

        try {
            return $this->userRepository->findByNick(new Nick($sourceId));
        } catch (InvalidArgumentException) {
        }

        return null;
    }

    private function mergeModeString(string $current, string $delta): string
    {
        if ('' === $delta) {
            return $current;
        }
        $base = str_replace(['+', '-'], '', $current);
        $add = '';
        $remove = '';
        $adding = true;
        foreach (str_split($delta) as $c) {
            if ('+' === $c) {
                $adding = true;
                continue;
            }
            if ('-' === $c) {
                $adding = false;
                continue;
            }
            if ($adding) {
                $add .= $c;
                $remove = str_replace($c, '', $remove);
            } else {
                $remove .= $c;
                $add = str_replace($c, '', $add);
            }
        }
        $base = preg_replace('/[' . preg_quote($remove, '/') . ']/', '', $base) ?? $base;
        $base .= $add;

        return '' === $base ? '' : '+' . $base;
    }
}
