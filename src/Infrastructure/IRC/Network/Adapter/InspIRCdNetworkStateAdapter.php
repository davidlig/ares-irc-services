<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Adapter;

use App\Domain\IRC\Event\FjoinReceivedEvent;
use App\Domain\IRC\Event\FmodeReceivedEvent;
use App\Domain\IRC\Event\FtopicReceivedEvent;
use App\Domain\IRC\Event\KickReceivedEvent;
use App\Domain\IRC\Event\LmodeReceivedEvent;
use App\Domain\IRC\Event\NickChangeReceivedEvent;
use App\Domain\IRC\Event\PartReceivedEvent;
use App\Domain\IRC\Event\QuitReceivedEvent;
use App\Domain\IRC\Event\ServerDelinkedEvent;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\Network\NetworkStateAdapterInterface;
use App\Domain\IRC\Network\NetworkUser;
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
use function base64_encode;
use function count;
use function inet_pton;
use function sprintf;

/**
 * InspIRCd protocol adapter: parses UID, NICK, QUIT, FJOIN, FMODE, LMODE,
 * FTOPIC, PART, KICK and dispatches domain events. Does not write to repos.
 */
final class InspIRCdNetworkStateAdapter implements NetworkStateAdapterInterface
{
    private const string PROTOCOL = 'inspircd';

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
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
            'SQUIT' => $this->handleSquit($message),
            'FJOIN' => $this->handleFjoin($message),
            'PART' => $this->handlePart($message),
            'KICK' => $this->handleKick($message),
            'FMODE' => $this->handleFmode($message),
            'LMODE' => $this->handleLmode($message),
            'FTOPIC' => $this->handleFtopic($message),
            default => null,
        };
    }

    /**
     * InspIRCd UID format: uuid timestamp nickname real_host displayed_host [real_user] displayed_user ip connect_time modes [mode_params] :realname
     * 1205: 0=uuid, 1=ts, 2=nick, 3=real_host, 4=displayed_host, 5=displayed_user, 6=ip, 7=connect_time, 8=modes, 9+=mode_params
     * 1206+: 0=uuid, 1=ts, 2=nick, 3=real_host, 4=displayed_host, 5=real_user, 6=displayed_user, 7=ip, 8=connect_time, 9=modes, 10+=mode_params.
     */
    private function handleUid(IRCMessage $message): void
    {
        $params = $message->params;
        if (count($params) < 10) {
            $this->logger->warning('Malformed UID message (not enough params)', [
                'raw' => $message->toRawLine(),
            ]);

            return;
        }

        $uidStr = $params[0];
        $timestamp = $params[1];
        $nickStr = $params[2];
        $hostname = $params[3];
        $cloakedHost = $params[4];
        $is1206 = count($params) >= 11;
        $username = $is1206 ? $params[6] : $params[5];
        $ipRaw = $is1206 ? $params[7] : $params[6];
        $umodes = $is1206 ? $params[9] : $params[8];
        $gecos = $message->trailing ?? '';
        $serverSid = $message->prefix ?? '';

        $ipBase64 = $this->encodeIpToBase64($ipRaw);

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
            virtualHost: $cloakedHost,
            modes: $umodes,
            connectedAt: $connectedAt,
            realName: $gecos,
            serverSid: $serverSid,
            ipBase64: $ipBase64,
            serviceStamp: 0,
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

    /**
     * InspIRCd sends IP as plain text (e.g. 127.0.0.1 or ::1). Normalize to base64 for NetworkUser compatibility.
     */
    private function encodeIpToBase64(string $ip): string
    {
        if ('' === $ip || '*' === $ip) {
            return $ip;
        }

        $binary = @inet_pton($ip);
        if (false !== $binary) {
            return base64_encode($binary);
        }

        return base64_encode($ip);
    }

    private function handleNick(IRCMessage $message): void
    {
        $newNickStr = $message->params[0] ?? '';
        $sourceId = $message->prefix ?? '';

        if ('' === $newNickStr || '' === $sourceId) {
            return;
        }

        $this->eventDispatcher->dispatch(new NickChangeReceivedEvent($sourceId, $newNickStr));
    }

    private function handleQuit(IRCMessage $message): void
    {
        $sourceId = $message->prefix ?? '';
        $reason = $message->trailing ?? '';

        if ('' === $sourceId) {
            return;
        }

        $this->eventDispatcher->dispatch(new QuitReceivedEvent($sourceId, $reason));
    }

    /**
     * SQUIT: server removed from network. Format :<source> SQUIT <server> :<reason>
     * server is the server that left (SID or name depending on InspIRCd version).
     */
    private function handleSquit(IRCMessage $message): void
    {
        $serverSid = $message->params[0] ?? '';
        $reason = $message->trailing ?? '';

        if ('' === $serverSid) {
            return;
        }

        $this->eventDispatcher->dispatch(new ServerDelinkedEvent($serverSid, $reason));
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

        $members = [];
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
            $members[] = ['uid' => $uid, 'role' => $role];
        }

        $this->eventDispatcher->dispatch(new FjoinReceivedEvent($channelName, $timestamp, $modeStr, $members));
    }

    private function handlePart(IRCMessage $message): void
    {
        $sourceId = $message->prefix ?? '';
        $channelStr = $message->params[0] ?? '';
        $reason = $message->trailing ?? '';

        if ('' === $sourceId || '' === $channelStr) {
            return;
        }

        try {
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $this->eventDispatcher->dispatch(new PartReceivedEvent($sourceId, $channelName, $reason, false));
    }

    private function handleKick(IRCMessage $message): void
    {
        $channelStr = $message->params[0] ?? '';
        $targetId = $message->params[1] ?? '';
        $reason = $message->trailing ?? '';

        if ('' === $channelStr || '' === $targetId) {
            return;
        }

        try {
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $this->eventDispatcher->dispatch(new KickReceivedEvent($channelName, $targetId, $reason));
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

        $this->eventDispatcher->dispatch(new FmodeReceivedEvent($channelName, $modeStr));
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

        $params = array_slice($message->params, 3);
        if (null !== $message->trailing && '' !== $message->trailing) {
            $params[] = $message->trailing;
        }

        $this->eventDispatcher->dispatch(new LmodeReceivedEvent($channelName, $modeChar, $params));
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

        $this->eventDispatcher->dispatch(new FtopicReceivedEvent($channelName, $topic));
    }
}
