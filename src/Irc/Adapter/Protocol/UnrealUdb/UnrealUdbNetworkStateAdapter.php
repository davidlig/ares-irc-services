<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Network\Event\CaseMappingChangedEvent;
use App\Irc\Adapter\Network\Event\ChannelJoinReceivedEvent;
use App\Irc\Adapter\Network\Event\ChannelKickReceivedEvent;
use App\Irc\Adapter\Network\Event\ChannelModeReceivedEvent;
use App\Irc\Adapter\Network\Event\ChannelPartReceivedEvent;
use App\Irc\Adapter\Network\Event\ChannelTopicReceivedEvent;
use App\Irc\Adapter\Network\Event\UserHostReceivedEvent;
use App\Irc\Adapter\Network\Event\UserMetadataReceivedEvent;
use App\Irc\Adapter\Network\Event\UserModeReceivedEvent;
use App\Irc\Adapter\Network\Event\UserNickChangeReceivedEvent;
use App\Irc\Adapter\Network\Event\UserQuitReceivedEvent;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\NetworkStateAdapterInterface;
use App\Irc\Domain\Event\ServerDelinkedEvent;
use App\Irc\Domain\Event\UserJoinedNetworkEvent;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\ValueObject\CaseMapping;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Ident;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use DateMalformedStringException;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_keys;
use function array_merge;
use function array_pop;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function in_array;
use function ltrim;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strcmp;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * UnrealIRCd protocol adapter: parses UID, NICK, QUIT, SJOIN, PART, KICK,
 * MODE, TOPIC, UMODE2, MD and dispatches domain events. Does not write to repos.
 */
final class UnrealUdbNetworkStateAdapter implements NetworkStateAdapterInterface
{
    private const string PROTOCOL = 'unrealudb';

    private ?string $directSid = null;

    private ?string $directName = null;

    /** @var array<string, array{name: string, parent: string}> keyed by SID */
    private array $servers = [];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function getSupportedProtocol(): string
    {
        return self::PROTOCOL;
    }

    public function handleMessage(IRCMessage $message): void
    {
        match ($message->command) {
            'PROTOCTL' => $this->handleProtoctl($message),
            'SERVER', 'SID' => $this->handleServer($message),
            'ERROR' => $this->resetServerTree(true),
            'UID' => $this->handleUid($message),
            'NICK' => $this->handleNick($message),
            'QUIT' => $this->handleQuit($message),
            'KILL' => $this->handleKill($message),
            'SQUIT' => $this->handleSquit($message),
            'SJOIN' => $this->handleSjoin($message),
            'PART' => $this->handlePart($message),
            'KICK' => $this->handleKick($message),
            'UMODE2', 'SVS2MODE', 'SVSMODE' => $this->handleUmode2($message),
            'SETHOST' => $this->handleSethost($message),
            'MD' => $this->handleMd($message),
            'TOPIC' => $this->handleTopic($message),
            'MODE', 'SMODE' => $this->handleMode($message),
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
            $decimal = ltrim($timestamp, '0');
            $maximum = (string) PHP_INT_MAX;
            if (1 !== preg_match('/^[0-9]+$/D', $timestamp)
                || strlen($decimal) > strlen($maximum)
                || (strlen($decimal) === strlen($maximum) && strcmp($decimal, $maximum) > 0)) {
                throw new InvalidArgumentException('Invalid unsigned UID timestamp.');
            }
            $connectedAt = new DateTimeImmutable('@' . (int) $timestamp);
            $nick = new Nick($nickStr);
            $uid = new Uid($uidStr);
            $ident = new Ident($username);
        } catch (DateMalformedStringException|InvalidArgumentException $e) {
            $this->logger->warning('UID skipped: invalid value — ' . $e->getMessage(), [
                'raw' => $message->toRawLine(),
            ]);

            return;
        }

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

    private function handleKill(IRCMessage $message): void
    {
        $target = $message->params[0] ?? '';
        if ('' === $target) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserQuitReceivedEvent($target, $message->trailing ?? ''));
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
     * server_sid is the SID or server name of the server that left (e.g. "002").
     */
    private function handleSquit(IRCMessage $message): void
    {
        $target = $message->params[0] ?? '';
        $reason = $message->trailing ?? '';

        if ('' === $target) {
            return;
        }

        $resolvedSid = $this->resolve($target);
        if (null !== $this->directSid && $resolvedSid === $this->directSid) {
            $delinkedSids = array_values(array_unique(array_merge([$this->directSid], array_keys($this->servers))));
            $this->resetServerTree();
            $this->eventDispatcher->dispatch(new ServerDelinkedEvent($resolvedSid, $reason, $delinkedSids));

            return;
        }

        if (null !== $resolvedSid) {
            $delinkedSids = [];
            $pending = [$resolvedSid];
            while (null !== ($removed = array_pop($pending))) {
                $delinkedSids[] = $removed;
                unset($this->servers[$removed]);
                foreach ($this->servers as $id => $server) {
                    if ($server['parent'] === $removed) {
                        $pending[] = $id;
                    }
                }
            }

            $this->eventDispatcher->dispatch(new ServerDelinkedEvent($resolvedSid, $reason, $delinkedSids));

            return;
        }

        $this->eventDispatcher->dispatch(new ServerDelinkedEvent($target, $reason, [$target]));
    }

    private function handleProtoctl(IRCMessage $message): void
    {
        if (null !== $message->prefix && (null === $this->directSid || $this->resolve($message->prefix) !== $this->directSid)) {
            return;
        }

        foreach ($message->params as $parameter) {
            if (str_starts_with($parameter, 'CASEMAPPING=')) {
                $mapping = CaseMapping::tryFrom(substr($parameter, 12));
                if (null !== $mapping) {
                    $this->eventDispatcher->dispatch(new CaseMappingChangedEvent($mapping));
                }
            }
            if (str_starts_with($parameter, 'SID=')) {
                $sid = substr($parameter, 4);
                if (1 === preg_match('/^[0-9][A-Z0-9]{2}$/D', $sid)) {
                    $this->directSid = $sid;
                }
            }
        }
    }

    private function handleServer(IRCMessage $message): void
    {
        $name = $message->params[0] ?? '';
        if ('SERVER' === $message->command && null === $message->prefix) {
            if ('' !== $name) {
                $this->directName = strtolower($name);
            }

            return;
        }

        $parent = $this->resolve($message->prefix ?? '');
        if (null === $parent || '' === $name || count($message->params) < 2) {
            return;
        }

        $key = 'SID' === $message->command ? ($message->params[2] ?? '') : strtolower($name);
        if ('SID' === $message->command && 1 !== preg_match('/^[0-9][A-Z0-9]{2}$/D', $key)) {
            return;
        }

        if ('' !== $key && !isset($this->servers[$key])) {
            $this->servers[$key] = ['name' => strtolower($name), 'parent' => $parent];
        }
    }

    private function resetServerTree(bool $resetCaseMapping = false): void
    {
        if ($resetCaseMapping) {
            $this->eventDispatcher->dispatch(new CaseMappingChangedEvent(CaseMapping::Ascii));
        }
        $this->directSid = null;
        $this->directName = null;
        $this->servers = [];
    }

    private function resolve(string $identity): ?string
    {
        if ('' === $identity) {
            return null;
        }

        if (null !== $this->directSid && ($identity === $this->directSid || strtolower($identity) === $this->directName)) {
            return $this->directSid;
        }

        foreach ($this->servers as $key => $server) {
            if ($identity === $key || strtolower($identity) === $server['name']) {
                return $key;
            }
        }

        return null;
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

            if (self::isAdminPrefixMemberEntry($entry)) {
                $memberEntry = $this->parseMemberEntry($entry);
                if (null !== $memberEntry) {
                    $members[] = $memberEntry;

                    continue;
                }
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
        $entry = $this->stripSjsbyPrefix($entry);
        $firstChar = $entry[0] ?? '';

        return match ($firstChar) {
            '&' => ['mode' => 'b', 'value' => substr($entry, 1)],
            '"' => ['mode' => 'e', 'value' => substr($entry, 1)],
            "'" => ['mode' => 'I', 'value' => substr($entry, 1)],
            default => null,
        };
    }

    private function stripSjsbyPrefix(string $entry): string
    {
        if (!str_starts_with($entry, '<')) {
            return $entry;
        }

        $closingPos = strpos($entry, '>');
        if (false === $closingPos) {
            return $entry;
        }

        return substr($entry, $closingPos + 1);
    }

    /**
     * Parses a member entry from SJOIN buffer, extracting UID and role.
     *
     * @return array{uid: Uid, role: ChannelMemberRole, prefixLetters: list<string>}|null
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
            '*' => ChannelMemberRole::Owner,
            '~' => ChannelMemberRole::Admin,
            '&' => ChannelMemberRole::Admin,
            '@' => ChannelMemberRole::Op,
            '%' => ChannelMemberRole::HalfOp,
            '+' => ChannelMemberRole::Voice,
            default => ChannelMemberRole::None,
        };
    }

    private static function isAdminPrefixMemberEntry(string $entry): bool
    {
        return 1 === preg_match('/^&[A-Z0-9]{9}$/i', $entry);
    }

    /**
     * @return list<string>
     */
    private static function parseSjoinEntryToLetters(string &$entry): array
    {
        $prefixChars = ['*', '~', '&', '@', '%', '+'];
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
        if ('UMODE2' === $message->command) {
            $sourceId = $message->prefix ?? '';
            $modeStr = $message->params[0] ?? $message->trailing ?? '';
        } else {
            $sourceId = $message->params[0] ?? '';
            $modeStr = $message->trailing ?? ($message->params[1] ?? '');
        }

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

        if ('' === $uidStr || '' === $key) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserMetadataReceivedEvent($uidStr, $key, $value));
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
