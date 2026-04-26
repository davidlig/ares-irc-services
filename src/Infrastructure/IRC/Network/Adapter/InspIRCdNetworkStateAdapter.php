<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Adapter;

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
use App\Infrastructure\IRC\Network\Event\ChannelJoinReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelKickReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelListModeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelModeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelPartReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelTopicReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserMetadataReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserModeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserNickChangeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserQuitReceivedEvent;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_slice;
use function base64_encode;
use function count;
use function inet_pton;
use function preg_match;
use function sprintf;
use function str_starts_with;

/**
 * InspIRCd protocol adapter: parses UID, NICK, QUIT, FJOIN, IJOIN, FMODE,
 * LMODE, FTOPIC, PART, KICK, MODE, METADATA, OPERTYPE and dispatches domain events.
 * Does not write to repos.
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
            'IJOIN' => $this->handleIjoin($message),
            'PART' => $this->handlePart($message),
            'KICK' => $this->handleKick($message),
            'FMODE' => $this->handleFmode($message),
            'LMODE' => $this->handleLmode($message),
            'FTOPIC' => $this->handleFtopic($message),
            'MODE' => $this->handleMode($message),
            'METADATA' => $this->handleMetadata($message),
            'OPERTYPE' => $this->handleOpertype($message),
            default => null,
        };
    }

    /**
     * InspIRCd 4.x (1206) UID format:
     * :serverSid UID uuid ts nick real_host displayed_host real_user displayed_user ip connect_time modes [mode_params] :realname
     * 0=uuid, 1=ts, 2=nick, 3=real_host, 4=displayed_host, 5=real_user, 6=displayed_user, 7=ip, 8=connect_time, 9=modes, 10+=mode_params.
     *
     * Note: ts (params[1]) is the nick change timestamp; connect_time (params[8]) is when the user connected.
     * Ares always negotiates protocol 1206 (v4), so 1205 (v3) format is not supported.
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
        $username = $params[6];
        $ipRaw = $params[7];
        $connectedAtRaw = $params[8];
        $umodes = $params[9];
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

        $connectedAt = new DateTimeImmutable('@' . $connectedAtRaw);

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

        $this->eventDispatcher->dispatch(new UserNickChangeReceivedEvent($sourceId, $newNickStr));
    }

    private function handleQuit(IRCMessage $message): void
    {
        $sourceId = $message->prefix ?? '';
        $reason = $message->trailing ?? '';

        if ('' === $sourceId) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserQuitReceivedEvent($sourceId, $reason));
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
            $prefixLetters = ChannelMemberRole::None !== $role ? [$role->toModeLetter()] : [];
            $members[] = ['uid' => $uid, 'role' => $role, 'prefixLetters' => $prefixLetters];
        }

        $this->eventDispatcher->dispatch(new ChannelJoinReceivedEvent($channelName, $timestamp, $modeStr, $members));
    }

    /**
     * InspIRCd 4.x IJOIN: post-burst join (user joins a channel after ENDBURST).
     * Format: :<uid> IJOIN <channel> <mode_hint> [<creation_ts>]
     * <mode_hint> is a numeric representing the user's prefix modes in the channel.
     * Prefix numeric mapping (protocol 1206): voice=1, halfop=2, op=4, admin=8, owner=16.
     * Multiple modes are OR'd (e.g. op+voice=5).
     * <creation_ts> is optional; when absent, the channel already exists on our side.
     */
    private function handleIjoin(IRCMessage $message): void
    {
        if (count($message->params) < 2) {
            return;
        }

        $uidStr = $message->prefix ?? '';
        $channelStr = $message->params[0];
        $modeHint = (int) ($message->params[1] ?? 0);
        $creationTs = (int) ($message->params[2] ?? 0);

        if ('' === $uidStr || '' === $channelStr) {
            return;
        }

        try {
            $uid = new Uid($uidStr);
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $role = self::roleFromModeHint($modeHint);

        $members = [['uid' => $uid, 'role' => $role, 'prefixLetters' => self::prefixLettersFromModeHint($modeHint)]];

        $this->eventDispatcher->dispatch(new ChannelJoinReceivedEvent($channelName, $creationTs, '', $members));
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

        $this->eventDispatcher->dispatch(new ChannelPartReceivedEvent($sourceId, $channelName, $reason, false));
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

        $this->eventDispatcher->dispatch(new ChannelKickReceivedEvent($channelName, $targetId, $reason));
    }

    private function handleFmode(IRCMessage $message): void
    {
        if (count($message->params) < 3) {
            return;
        }

        $channelStr = $message->params[0];
        $modeStr = $message->params[2] ?? '';
        $modeParams = array_slice($message->params, 3);
        if (null !== $message->trailing && '' !== $message->trailing) {
            $modeParams[] = $message->trailing;
        }

        try {
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $this->eventDispatcher->dispatch(new ChannelModeReceivedEvent($channelName, $modeStr, $modeParams));
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

        $this->eventDispatcher->dispatch(new ChannelListModeReceivedEvent($channelName, $modeChar, $params));
    }

    private function handleFtopic(IRCMessage $message): void
    {
        if (count($message->params) < 2) {
            return;
        }

        $channelStr = $message->params[0];
        $topic = $message->trailing;
        $setterNick = null;
        $sourceUid = null;
        if (count($message->params) >= 4) {
            $rawSetter = $message->params[3];
            if (str_contains($rawSetter, '!')) {
                $setterNick = explode('!', $rawSetter, 2)[0];
            } elseif (!str_contains($rawSetter, '.')) {
                $setterNick = $rawSetter;
            }
        } else {
            $sourceUid = $message->prefix;
        }

        try {
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $this->eventDispatcher->dispatch(new ChannelTopicReceivedEvent($channelName, $topic, $setterNick, $sourceUid));
    }

    private function handleMode(IRCMessage $message): void
    {
        $target = $message->params[0] ?? '';
        $modeStr = $message->params[1] ?? '';

        if ('' === $target || '' === $modeStr) {
            return;
        }

        if (str_starts_with($target, '#')) {
            try {
                $channelName = new ChannelName($target);
            } catch (InvalidArgumentException) {
                return;
            }

            $modeParams = array_slice($message->params, 2);
            if (null !== $message->trailing && '' !== $message->trailing) {
                $modeParams[] = $message->trailing;
            }

            $this->eventDispatcher->dispatch(new ChannelModeReceivedEvent($channelName, $modeStr, $modeParams));

            return;
        }

        if (preg_match('/^[0-9][0-9A-Z]{8}$/', $target)) {
            $sourceId = $message->prefix ?? $target;
            $this->eventDispatcher->dispatch(new UserModeReceivedEvent($sourceId, $modeStr));
        }
    }

    private function handleMetadata(IRCMessage $message): void
    {
        $target = $message->params[0] ?? '';
        $key = $message->params[1] ?? '';
        $value = $message->trailing ?? '';

        if ('' === $target || '' === $key) {
            return;
        }

        if (preg_match('/^[0-9][0-9A-Z]{8}$/', $target)) {
            $this->eventDispatcher->dispatch(new UserMetadataReceivedEvent($target, $key, $value));
        }
    }

    private function handleOpertype(IRCMessage $message): void
    {
        $sourceId = $message->prefix ?? '';
        $operType = $message->trailing ?? ($message->params[0] ?? '');

        if ('' === $sourceId) {
            return;
        }

        $this->logger->info(sprintf('User %s opered up with type %s', $sourceId, $operType));
    }

    private const int MODE_HINT_VOICE = 1;

    private const int MODE_HINT_HALFOP = 2;

    private const int MODE_HINT_OP = 4;

    private const int MODE_HINT_ADMIN = 8;

    private const int MODE_HINT_OWNER = 16;

    private static function roleFromModeHint(int $modeHint): ChannelMemberRole
    {
        if (0 !== ($modeHint & self::MODE_HINT_OWNER)) {
            return ChannelMemberRole::Owner;
        }
        if (0 !== ($modeHint & self::MODE_HINT_ADMIN)) {
            return ChannelMemberRole::Admin;
        }
        if (0 !== ($modeHint & self::MODE_HINT_OP)) {
            return ChannelMemberRole::Op;
        }
        if (0 !== ($modeHint & self::MODE_HINT_HALFOP)) {
            return ChannelMemberRole::HalfOp;
        }
        if (0 !== ($modeHint & self::MODE_HINT_VOICE)) {
            return ChannelMemberRole::Voice;
        }

        return ChannelMemberRole::None;
    }

    /**
     * @return list<string>
     */
    private static function prefixLettersFromModeHint(int $modeHint): array
    {
        $letters = [];
        if (0 !== ($modeHint & self::MODE_HINT_OWNER)) {
            $letters[] = 'q';
        }
        if (0 !== ($modeHint & self::MODE_HINT_ADMIN)) {
            $letters[] = 'a';
        }
        if (0 !== ($modeHint & self::MODE_HINT_OP)) {
            $letters[] = 'o';
        }
        if (0 !== ($modeHint & self::MODE_HINT_HALFOP)) {
            $letters[] = 'h';
        }
        if (0 !== ($modeHint & self::MODE_HINT_VOICE)) {
            $letters[] = 'v';
        }

        return $letters;
    }
}
