<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Adapter;

use App\Domain\IRC\Event\ChannelModesChangedEvent;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\ChannelTopicChangedEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Event\UserLeftChannelEvent;
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
use function preg_quote;
use function preg_replace;
use function str_replace;
use function str_split;

/**
 * InspIRCd protocol adapter: parses UID, NICK, QUIT, FJOIN, FMODE, LMODE,
 * FTOPIC, PART, KICK and dispatches domain events. Does not write to repos.
 */
final class InspIRCdNetworkStateAdapter implements NetworkStateAdapterInterface
{
    private const PROTOCOL = 'inspircd';

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
            'FJOIN' => $this->handleFjoin($message),
            'PART' => $this->handlePart($message),
            'KICK' => $this->handleKick($message),
            'FMODE' => $this->handleFmode($message),
            'LMODE' => $this->handleLmode($message),
            'FTOPIC' => $this->handleFtopic($message),
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
            return;
        }

        try {
            $newNick = new Nick($newNickStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $oldNick = $user->getNick();

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
    }

    private function handleFjoin(IRCMessage $message): void
    {
        if (count($message->params) < 3) {
            return;
        }

        $channelStr = $message->params[0];
        $timestamp = (int) $message->params[1];
        $modeStr = $message->params[2] ?? '';
        $buffer = trim($message->trailing ?? '');

        try {
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            return;
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

            $comma = strpos($entry, ',');
            $colon = strpos($entry, ':');
            if (false === $comma || false === $colon || $colon < $comma) {
                continue;
            }

            $prefixLetter = substr($entry, 0, $comma);
            $uidStr = substr($entry, $comma + 1, $colon - $comma - 1);

            try {
                $uid = new Uid($uidStr);
            } catch (InvalidArgumentException) {
                continue;
            }

            $role = ChannelMemberRole::fromModeLetter($prefixLetter) ?? ChannelMemberRole::None;
            $channel->syncMember($uid, $role);
            $joinedUids[] = $uid;
        }

        if ($isNewChannel) {
            $this->eventDispatcher->dispatch(new ChannelSyncedEvent($channel));
        } else {
            foreach ($joinedUids as $uid) {
                $member = $channel->getMember($uid);
                $role = $member?->role ?? ChannelMemberRole::None;
                $this->eventDispatcher->dispatch(new UserJoinedChannelEvent($uid, $channelName, $role));
            }
            $this->eventDispatcher->dispatch(new ChannelSyncedEvent($channel));
        }
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

        $this->eventDispatcher->dispatch(
            new UserLeftChannelEvent($target->uid, $target->getNick(), $channelName, $reason, wasKicked: true)
        );
    }

    private function handleFmode(IRCMessage $message): void
    {
        if (count($message->params) < 3) {
            return;
        }

        $channelStr = $message->params[0];
        $modeStr = $message->params[2] ?? '';

        try {
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $channel = $this->channelRepository->findByName($channelName);
        if (null === $channel) {
            return;
        }

        $current = $channel->getModes();
        $channel->updateModes($this->mergeModeString($current, $modeStr));
        $this->eventDispatcher->dispatch(new ChannelModesChangedEvent($channel));
    }

    private function handleLmode(IRCMessage $message): void
    {
        if (count($message->params) < 4) {
            return;
        }

        $channelStr = $message->params[0];
        $modeChar = $message->params[2] ?? '';

        try {
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $channel = $this->channelRepository->findByName($channelName);
        if (null === $channel) {
            return;
        }

        $params = array_slice($message->params, 3);
        if (null !== $message->trailing && '' !== $message->trailing) {
            $params[] = $message->trailing;
        }

        for ($i = 0; $i < count($params); $i += 3) {
            $mask = $params[$i] ?? '';
            if ('' === $mask) {
                break;
            }
            if ('b' === $modeChar) {
                $channel->addBan($mask);
            } elseif ('e' === $modeChar) {
                $channel->addExempt($mask);
            } elseif ('I' === $modeChar) {
                $channel->addInviteException($mask);
            }
        }

        $this->eventDispatcher->dispatch(new ChannelModesChangedEvent($channel));
    }

    private function handleFtopic(IRCMessage $message): void
    {
        if (count($message->params) < 2) {
            return;
        }

        $channelStr = $message->params[0];
        $topic = $message->trailing;

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
