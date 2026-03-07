<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Adapter;

use App\Domain\IRC\Event\FjoinReceivedEvent;
use App\Domain\IRC\Event\FtopicReceivedEvent;
use App\Domain\IRC\Event\KickReceivedEvent;
use App\Domain\IRC\Event\ModeReceivedEvent;
use App\Domain\IRC\Event\NickChangeReceivedEvent;
use App\Domain\IRC\Event\PartReceivedEvent;
use App\Domain\IRC\Event\QuitReceivedEvent;
use App\Domain\IRC\Event\ServerDelinkedEvent;
use App\Domain\IRC\Event\SethostReceivedEvent;
use App\Domain\IRC\Event\Umode2ReceivedEvent;
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
use function count;
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
            // Unreal SJOIN: "modes and parameters, eg: +lk 666 key" — params[3], params[4], ... in order of mode letters
            $modeParams = array_values(array_slice($message->params, 3));
        }

        $listModes = ['b' => [], 'e' => [], 'I' => []];
        $members = [];

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
                $listModes['b'][] = substr($entry, 1);
                continue;
            }

            if ('"' === $firstChar) {
                $listModes['e'][] = substr($entry, 1);
                continue;
            }

            if ("'" === $firstChar) {
                $listModes['I'][] = substr($entry, 1);
                continue;
            }

            $prefixLetters = ChannelMemberRole::fromSjoinEntryToLetters($entry);
            $role = ChannelMemberRole::highestRoleFromLetters($prefixLetters);

            try {
                $uid = new Uid($entry);
            } catch (InvalidArgumentException) {
                $this->logger->debug('SJOIN: skipping non-UID member entry: ' . $entry);
                continue;
            }

            $members[] = ['uid' => $uid, 'role' => $role, 'prefixLetters' => $prefixLetters];
        }

        $this->eventDispatcher->dispatch(new FjoinReceivedEvent($channelName, $timestamp, $modeStr, $members, $listModes, $modeParams));
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

    private function handleUmode2(IRCMessage $message): void
    {
        $sourceId = $message->prefix ?? '';
        $modeStr = $message->params[0] ?? $message->trailing ?? '';

        if ('' === $sourceId || '' === $modeStr) {
            return;
        }

        $this->eventDispatcher->dispatch(new Umode2ReceivedEvent($sourceId, $modeStr));
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

        $this->eventDispatcher->dispatch(new SethostReceivedEvent($sourceId, $newHost));
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

        $this->eventDispatcher->dispatch(new FtopicReceivedEvent($channelName, $topic, $setterNick));
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

        $this->eventDispatcher->dispatch(new ModeReceivedEvent($channelName, $modeStr, $modeParams));
    }
}
