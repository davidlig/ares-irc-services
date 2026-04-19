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
use App\Infrastructure\IRC\Network\Event\ChannelModeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelPartReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelTopicReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserHostReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserModeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserNickChangeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserQuitReceivedEvent;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_slice;
use function count;
use function in_array;
use function sprintf;
use function str_starts_with;
use function strpos;
use function substr;

/**
 * UnrealIRCd protocol adapter: parses UID, NICK, QUIT, SJOIN, PART, KICK,
 * MODE, TOPIC, UMODE2, MD and dispatches domain events. Does not write to repos.
 */
final class UnrealIRCdNetworkStateAdapter implements NetworkStateAdapterInterface
{
    private const string PROTOCOL = 'unreal';

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
            'SJOIN' => $this->handleSjoin($message),
            'PART' => $this->handlePart($message),
            'KICK' => $this->handleKick($message),
            'UMODE2' => $this->handleUmode2($message),
            'SETHOST' => $this->handleSethost($message),
            'MD' => $this->handleMd($message),
            'TOPIC' => $this->handleTopic($message),
            'MODE' => $this->handleMode($message),
            default => null,
        };
    }

    /**
     * UnrealIRCd UID format: nickname hopcount timestamp username hostname uid servicestamp usermodes virtualhost cloakedhost ip :gecos
     * Params: 0=nick, 1=hop, 2=timestamp, 3=username, 4=hostname, 5=uid, 6=servicestamp, 7=umodes, 8=virthost, 9=cloakedhost, 10=ip (base64).
     */
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
     * SQUIT: server removed from network. Format :<source> SQUIT <server_sid> :<reason>
     * server_sid is the SID of the server that left (e.g. "002").
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
        $modeParams = [];
        if (isset($message->params[2]) && str_starts_with((string) $message->params[2], '+')) {
            $modeStr = (string) $message->params[2];
            $modeParams = array_values(array_slice($message->params, 3));
        }

        $listModes = ['b' => [], 'e' => [], 'I' => []];
        $members = [];

        foreach (explode(' ', $buffer) as $entry) {
            $entry = trim($entry);
            if ('' === $entry) {
                continue;
            }

            $listModeEntry = $this->parseListModeEntry($entry);
            if (null !== $listModeEntry) {
                $listModes[$listModeEntry['mode']][] = $listModeEntry['value'];
                continue;
            }

            $memberEntry = $this->parseMemberEntry($entry);
            if (null !== $memberEntry) {
                $members[] = $memberEntry;
            }
        }

        $this->eventDispatcher->dispatch(new ChannelJoinReceivedEvent($channelName, $timestamp, $modeStr, $members, $listModes, $modeParams));
    }

    /**
     * Parses a list mode entry (ban/exempt/invex) from SJOIN buffer.
     *
     * @return array{mode: string, value: string}|null
     */
    private function parseListModeEntry(string $entry): ?array
    {
        $firstChar = $entry[0] ?? '';

        if ('&' === $firstChar) {
            return ['mode' => 'b', 'value' => substr($entry, 1)];
        }

        if ('"' === $firstChar) {
            return ['mode' => 'e', 'value' => substr($entry, 1)];
        }

        if ("'" === $firstChar) {
            return ['mode' => 'I', 'value' => substr($entry, 1)];
        }

        return null;
    }

    /**
     * Parses a member entry from SJOIN buffer, extracting UID and role.
     *
     * @return array{uid: Uid, role: ChannelMemberRole, prefixLetters: string}|null
     */
    private function parseMemberEntry(string $entry): ?array
    {
        $entry = $this->stripExtAccount($entry);
        $prefixLetters = self::parseSjoinEntryToLetters($entry);
        $role = ChannelMemberRole::highestRoleFromLetters($prefixLetters);

        try {
            $uid = new Uid($entry);
        } catch (InvalidArgumentException) {
            $this->logger->debug('SJOIN: skipping non-UID member entry: ' . $entry);

            return null;
        }

        return ['uid' => $uid, 'role' => $role, 'prefixLetters' => $prefixLetters];
    }

    private static function roleFromSjoinPrefix(string $prefix): ChannelMemberRole
    {
        return match ($prefix) {
            '+' => ChannelMemberRole::Voice,
            '%' => ChannelMemberRole::HalfOp,
            '@' => ChannelMemberRole::Op,
            '~' => ChannelMemberRole::Admin,
            '*' => ChannelMemberRole::Owner,
            default => ChannelMemberRole::None,
        };
    }

    /**
     * @return list<string>
     */
    private static function parseSjoinEntryToLetters(string &$entry): array
    {
        $prefixChars = ['+', '%', '@', '~', '*'];
        $letters = [];
        while ('' !== $entry && in_array($entry[0], $prefixChars, true)) {
            $role = self::roleFromSjoinPrefix($entry[0]);
            $entry = substr($entry, 1);
            if (ChannelMemberRole::None !== $role) {
                $letter = $role->toModeLetter();
                if ('' !== $letter && !in_array($letter, $letters, true)) {
                    $letters[] = $letter;
                }
            }
        }

        return $letters;
    }

    /**
     * Strips <ext:account> prefix from SJOIN entry if present.
     */
    private function stripExtAccount(string $entry): string
    {
        if (str_starts_with($entry, '<')) {
            $closingPos = strpos($entry, '>');
            if (false !== $closingPos) {
                return substr($entry, $closingPos + 1);
            }
        }

        return $entry;
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

    private function handleUmode2(IRCMessage $message): void
    {
        $sourceId = $message->prefix ?? '';
        $modeStr = $message->params[0] ?? $message->trailing ?? '';

        if ('' === $sourceId || '' === $modeStr) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserModeReceivedEvent($sourceId, $modeStr));
    }

    /**
     * SETHOST: user's displayed host changed (e.g. after CHGHOST or clear). Format :uid SETHOST :newhost.
     */
    private function handleSethost(IRCMessage $message): void
    {
        $sourceId = $message->prefix ?? '';
        $newHost = $message->trailing ?? '';

        if ('' === $sourceId) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserHostReceivedEvent($sourceId, $newHost));
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
        $setterNick = null;
        $setterParam = $message->params[1] ?? '';
        if ('' !== $setterParam && str_contains($setterParam, '!')) {
            $setterNick = explode('!', $setterParam, 2)[0];
        } elseif ('' !== $setterParam) {
            $setterNick = $setterParam;
        }

        if ('' === $channelStr) {
            return;
        }

        try {
            $channelName = new ChannelName($channelStr);
        } catch (InvalidArgumentException) {
            return;
        }

        $this->eventDispatcher->dispatch(new ChannelTopicReceivedEvent($channelName, $topic, $setterNick));
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

        $this->eventDispatcher->dispatch(new ChannelModeReceivedEvent($channelName, $modeStr, $modeParams));
    }
}
