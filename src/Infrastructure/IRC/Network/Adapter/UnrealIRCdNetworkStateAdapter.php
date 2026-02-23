<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Adapter;

use App\Domain\IRC\Event\ChannelModesChangedEvent;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\ChannelTopicChangedEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Event\UserLeftChannelEvent;
use App\Domain\IRC\Event\UserModeChangedEvent;
use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\Network\NetworkStateAdapterInterface;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\ChannelRepositoryInterface;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Ident;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_slice;
use function count;
use function sprintf;

/**
 * UnrealIRCd protocol adapter: parses UID, NICK, QUIT, SJOIN, PART, KICK,
 * MODE, TOPIC, UMODE2, MD and dispatches domain events. Does not write to repos.
 */
final class UnrealIRCdNetworkStateAdapter implements NetworkStateAdapterInterface
{
    private const string PROTOCOL = 'unreal';

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly NetworkUserRepositoryInterface $userRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getSupportedProtocol(): string
    {
        return self::PROTOCOL;
    }

    public function handleMessage(IRCMessage $message): void
    {
        match ($message->command) {
            'UID' => $this->handleUid($message),
            'NICK' => $this->handleNick($message),
            'QUIT' => $this->handleQuit($message),
            'SJOIN' => $this->handleSjoin($message),
            'PART' => $this->handlePart($message),
            'KICK' => $this->handleKick($message),
            'UMODE2' => $this->handleUmode2($message),
            'MD' => $this->handleMd($message),
            'TOPIC' => $this->handleTopic($message),
            'MODE' => $this->handleMode($message),
            default => null,
        };
    }

    private function handleUid(IRCMessage $message): void
    {
        if (count($message->params) < 11) {
            $this->logger->warning('Malformed UID message (not enough params)', [
                'raw' => $message->toRawLine(),
            ]);

            return;
        }

        [, , $timestamp, $username, $hostname, $uidStr, $serviceStamp, $umodes, $virthost, $cloakedHost, $ipBase64]
            = $message->params;

        $nickStr = $message->params[0];
        $gecos = $message->trailing ?? '';
        $serverSid = $message->prefix ?? '';

        try {
            $nick = new Nick($nickStr);
            $uid = new Uid($uidStr);
            $ident = new Ident($username);
        } catch (InvalidArgumentException $e) {
            $this->logger->warning('UID skipped: invalid value — ' . $e->getMessage(), [
                'raw' => $message->toRawLine(),
            ]);

            return;
        }

        $connectedAt = new DateTimeImmutable('@' . $timestamp);

        $user = new NetworkUser(
            uid: $uid,
            nick: $nick,
            ident: $ident,
            hostname: $hostname,
            cloakedHost: $cloakedHost,
            virtualHost: $virthost,
            modes: $umodes,
            connectedAt: $connectedAt,
            realName: $gecos,
            serverSid: $serverSid,
            ipBase64: $ipBase64,
            serviceStamp: (int) $serviceStamp,
        );

        $this->logger->info(sprintf(
            'User joined network: %s (%s@%s) [%s]',
            $nick->value,
            $ident->value,
            $user->getDisplayHost(),
            $uid->value,
        ));

        $this->eventDispatcher->dispatch(new UserJoinedNetworkEvent($user));
    }

    private function handleNick(IRCMessage $message): void
    {
        $newNickStr = $message->params[0] ?? '';
        $sourceId = $message->prefix ?? '';

        if ('' === $newNickStr || '' === $sourceId) {
            return;
        }

        $user = $this->resolveUser($sourceId);

        if (null === $user) {
            $this->logger->warning('NICK received for unknown source: ' . $sourceId);

            return;
        }

        try {
            $newNick = new Nick($newNickStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $oldNick = $user->getNick();

        $this->logger->info(sprintf('Nick change: %s → %s [%s]', $oldNick->value, $newNick->value, $user->uid->value));

        // Apply -r to the in-memory user before dispatching so nick-protection always sees
        // the user as not identified, regardless of listener order. UnrealIRCd strips +r on
        // every nick change but does NOT send UMODE2 -r to services.
        $user->applyModeChange('-r');
        $this->eventDispatcher->dispatch(new UserModeChangedEvent($user->uid, '-r'));
        $this->eventDispatcher->dispatch(new UserNickChangedEvent($user->uid, $oldNick, $newNick));
    }

    private function handleQuit(IRCMessage $message): void
    {
        $sourceId = $message->prefix ?? '';
        $reason = $message->trailing ?? '';

        if ('' === $sourceId) {
            return;
        }

        $user = $this->resolveUser($sourceId);

        if (null === $user) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserQuitNetworkEvent(
            uid: $user->uid,
            nick: $user->getNick(),
            reason: $reason,
            ident: $user->ident->value,
            displayHost: $user->getDisplayHost(),
        ));

        $this->logger->info(sprintf('User quit: %s [%s] — %s', $user->getNick()->value, $user->uid->value, $reason));
    }

    private function handleSjoin(IRCMessage $message): void
    {
        if (count($message->params) < 2) {
            return;
        }

        $timestamp = (int) $message->params[0];
        $channelStr = $message->params[1];
        $buffer = trim($message->trailing ?? '');

        try {
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            $this->logger->warning('SJOIN: invalid channel name: ' . $channelStr);

            return;
        }

        $modeStr = '';
        if (isset($message->params[2]) && str_starts_with($message->params[2], '+')) {
            $modeStr = implode(' ', array_slice($message->params, 2));
        }

        $channel = $this->channelRepository->findByName($channelName);
        $isNewChannel = null === $channel;

        if ($isNewChannel) {
            $channel = new Channel(
                name: $channelName,
                modes: $modeStr,
                createdAt: new DateTimeImmutable('@' . $timestamp),
            );
        } else {
            if ('' !== $modeStr) {
                $channel->updateModes($modeStr);
            }
        }

        $joinedUids = [];

        foreach (explode(' ', $buffer) as $entry) {
            $entry = trim($entry);

            if ('' === $entry) {
                continue;
            }

            if (str_starts_with($entry, '<')) {
                $closingPos = strpos($entry, '>');
                if (false !== $closingPos) {
                    $entry = substr($entry, $closingPos + 1);
                }
            }

            $firstChar = $entry[0] ?? '';

            if ('&' === $firstChar) {
                $channel->addBan(substr($entry, 1));
                continue;
            }

            if ('"' === $firstChar) {
                $channel->addExempt(substr($entry, 1));
                continue;
            }

            if ("'" === $firstChar) {
                $channel->addInviteException(substr($entry, 1));
                continue;
            }

            $role = ChannelMemberRole::fromSjoinEntry($entry);

            try {
                $uid = new Uid($entry);
            } catch (InvalidArgumentException) {
                $this->logger->debug('SJOIN: skipping non-UID member entry: ' . $entry);
                continue;
            }

            $channel->syncMember($uid, $role);
            $joinedUids[] = [$uid, $role];
        }

        if ($isNewChannel) {
            $this->logger->info(sprintf(
                'Channel synced: %s [%s, %d members]',
                $channelName->value,
                $modeStr ?: 'no modes',
                $channel->getMemberCount(),
            ));
        } else {
            foreach ($joinedUids as [$uid, $role]) {
                $this->logger->info(sprintf(
                    'User %s joined %s [role: %s]',
                    $uid->value,
                    $channelName->value,
                    $role->label(),
                ));

                $this->eventDispatcher->dispatch(new UserJoinedChannelEvent($uid, $channelName, $role));
            }
        }

        $this->eventDispatcher->dispatch(new ChannelSyncedEvent($channel));
    }

    private function handlePart(IRCMessage $message): void
    {
        $sourceId = $message->prefix ?? '';
        $channelStr = $message->params[0] ?? '';
        $reason = $message->trailing ?? '';

        if ('' === $sourceId || '' === $channelStr) {
            return;
        }

        $user = $this->resolveUser($sourceId);

        if (null === $user) {
            return;
        }

        try {
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $this->logger->info(sprintf(
            'User %s parted %s [%s]',
            $user->getNick()->value,
            $channelName->value,
            $reason,
        ));

        $this->eventDispatcher->dispatch(
            new UserLeftChannelEvent($user->uid, $user->getNick(), $channelName, $reason, wasKicked: false)
        );
    }

    private function handleKick(IRCMessage $message): void
    {
        $channelStr = $message->params[0] ?? '';
        $targetId = $message->params[1] ?? '';
        $reason = $message->trailing ?? '';

        if ('' === $channelStr || '' === $targetId) {
            return;
        }

        $target = $this->resolveUser($targetId);

        if (null === $target) {
            return;
        }

        try {
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $this->logger->info(sprintf(
            'User %s was kicked from %s [%s]',
            $target->getNick()->value,
            $channelName->value,
            $reason,
        ));

        $this->eventDispatcher->dispatch(
            new UserLeftChannelEvent($target->uid, $target->getNick(), $channelName, $reason, wasKicked: true)
        );
    }

    private function handleUmode2(IRCMessage $message): void
    {
        $sourceId = $message->prefix ?? '';
        $modeStr = $message->params[0] ?? $message->trailing ?? '';

        if ('' === $sourceId || '' === $modeStr) {
            return;
        }

        $user = $this->resolveUser($sourceId);

        if (null === $user) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserModeChangedEvent($user->uid, $modeStr));

        $this->logger->debug(sprintf(
            'UMODE2: %s [%s] modes → %s',
            $user->getNick()->value,
            $user->uid->value,
            $user->getModes(),
        ));
    }

    private function handleMd(IRCMessage $message): void
    {
        if ('client' !== ($message->params[0] ?? '')) {
            return;
        }

        $uidStr = $message->params[1] ?? '';
        $key = $message->params[2] ?? '';
        $value = $message->trailing ?? ($message->params[3] ?? '');

        if ('' === $uidStr || 'account' !== $key) {
            return;
        }

        $this->logger->debug(sprintf('MD: client %s account = %s', $uidStr, $value));
    }

    private function handleTopic(IRCMessage $message): void
    {
        $channelStr = $message->params[0] ?? '';
        $topic = $message->trailing;

        if ('' === $channelStr) {
            return;
        }

        try {
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $channel = $this->channelRepository->findByName($channelName);
        if (null === $channel) {
            return;
        }

        $channel->updateTopic($topic);
        $this->eventDispatcher->dispatch(new ChannelTopicChangedEvent($channel));
        $this->logger->debug(sprintf('TOPIC %s: %s', $channelStr, $topic ?? '(cleared)'));
    }

    private function handleMode(IRCMessage $message): void
    {
        if (count($message->params) < 2) {
            return;
        }

        $channelStr = $message->params[0];
        $modeStr = $message->params[1];
        $modeParams = array_slice($message->params, 2);
        if (null !== $message->trailing && '' !== $message->trailing) {
            $modeParams[] = $message->trailing;
        }

        try {
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $channel = $this->channelRepository->findByName($channelName);
        if (null === $channel) {
            return;
        }

        $paramIdx = 0;
        $adding = true;

        foreach (str_split($modeStr) as $char) {
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
                if ($paramIdx >= count($modeParams)) {
                    break;
                }
                $targetId = $modeParams[$paramIdx];
                ++$paramIdx;
                $user = $this->resolveUser($targetId);
                if (null !== $user) {
                    $channel->syncMember($user->uid, $adding ? $role : ChannelMemberRole::None);
                }
                continue;
            }

            if ('b' === $char || 'e' === $char || 'I' === $char) {
                if ($paramIdx >= count($modeParams)) {
                    break;
                }
                $mask = $modeParams[$paramIdx];
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

        $this->eventDispatcher->dispatch(new ChannelModesChangedEvent($channel));
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
}
