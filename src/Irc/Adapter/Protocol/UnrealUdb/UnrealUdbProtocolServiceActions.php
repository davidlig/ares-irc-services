<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionStateInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Shared\Application\Port\OperclassServiceActionsInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function count;
use function implode;
use function sprintf;
use function str_split;

/**
 * UnrealUdb service actions: UDB-native behavior.
 *
 * UDB applies live effects natively from its DB records, so most traditional
 * SVS, MODE and TOPIC commands are unnecessary:
 * - setUserAccount / setUserMode / setUserVhost / forceNick: no-ops — UDB
 *   identifies via N::<nick>::pass (+r, oper, vhost, modes) and force-renames
 *   unauthorized holders itself.
 * - setChannelModes: persisted as C::<#chan>::modes (MLOCK) or
 *   C::<#chan>::persistent; only ban/prefix operations still emit MODE.
 * - setChannelMemberMode: founder +q is UDB-owned; other ranks keep MODE.
 * - setChannelTopic: persisted as C::<#chan>::topic.
 * - addGline/removeGline: K::G records with duration/reason children.
 * Commands that UDB cannot perform (KILL, INVITE, JOIN/PART, KICK, UID/QUIT)
 * are kept unchanged.
 */
final readonly class UnrealUdbProtocolServiceActions implements ProtocolServiceActionsInterface, OperclassServiceActionsInterface
{
    public function __construct(
        private ActiveConnectionHolder $connectionHolder,
        private UdbRecordWriterInterface $recordWriter,
        private ?UdbSessionStateInterface $sessionState = null,
        private UnrealUdbServiceIntroductionFormatter $introductionFormatter = new UnrealUdbServiceIntroductionFormatter(),
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function setUserAccount(string $serverSid, string $targetUid, string $accountName): void
    {
        // UDB identifies users natively via N::<nick>::pass (NICK nick:pass / GHOST).
    }

    public function setUserMode(string $serverSid, string $targetUid, string $modes, array $params = []): void
    {
        // UDB applies oper/modes natively via N::<nick>::oper and N::<nick>::modes.
    }

    public function setUserOperclass(string $serverSid, string $targetUid, string $targetNickname, ?string $operclass): void
    {
        $path = $targetNickname . '::oper';
        if (null === $operclass || '' === $operclass) {
            $this->recordWriter->delete('N', $path);

            return;
        }

        if (null === $this->sessionState || !$this->sessionState->isOperclassGloballyAvailable($operclass)) {
            $this->logger->warning('Refusing UDB operclass assignment not present in the READY OCLG view.', ['operclass' => $operclass]);

            return;
        }

        $this->recordWriter->insert('N', $path, $operclass);
    }

    /**
     * @return list<string>
     */
    public function getAvailableOperclasses(): array
    {
        return $this->sessionState?->getAvailableOperclasses() ?? [];
    }

    public function setUserVhost(string $serverSid, string $targetUid, string $vhost, string $cloakedHost = ''): void
    {
        // UDB applies vhosts natively via N::<nick>::vhost.
    }

    public function introduceService(string $serverSid, string $nick, string $ident, string $vhost, string $uid, string $realname, string $serviceKey = ''): void
    {
        $line = $this->introductionFormatter->formatIntroduction(
            $serverSid,
            $nick,
            $ident,
            $vhost,
            $uid,
            $realname,
            $serviceKey,
        );
        $this->write($line);
    }

    public function forceNick(string $serverSid, string $targetUid, string $newNick): void
    {
        // UDB force-renames unauthorized holders of registered nicks natively.
    }

    public function killUser(string $serverSid, string $targetUid, string $reason): void
    {
        $this->write(sprintf(':%s KILL %s :%s', $serverSid, $targetUid, $reason));
    }

    public function setChannelModes(string $serverSid, string $channelName, string $modeStr, array $params = [], string $serviceUid = '', ?int $channelTimestamp = null): void
    {
        [$modeStr, $params] = $this->stripUdbManagedModes($modeStr, $params);
        if ('' === $modeStr) {
            return;
        }

        $this->writeModeLine($serverSid, $channelName, $modeStr, $params, $serviceUid);
    }

    public function setChannelMemberMode(string $serverSid, string $channelName, string $targetUid, string $modeLetter, bool $add, string $serviceUid = '', ?int $channelTimestamp = null): void
    {
        if ('q' === $modeLetter) {
            // UDB owns founder +q via C::<#chan>::founder.
            return;
        }

        $prefix = '' !== $serviceUid ? $serviceUid : $serverSid;
        $delta = $add ? '+' . $modeLetter : '-' . $modeLetter;
        $this->write(sprintf(':%s MODE %s %s %s', $prefix, $channelName, $delta, $targetUid));
    }

    public function inviteUserToChannel(string $serverSid, string $channelName, string $targetUid, string $serviceUid = '', ?int $channelTimestamp = null): void
    {
        $prefix = '' !== $serviceUid ? $serviceUid : $serverSid;
        $this->write(sprintf(':%s INVITE %s %s', $prefix, $targetUid, $channelName));
    }

    public function joinChannelAsService(string $serverSid, string $channelName, string $serviceUid, string $maxPrefixLetter, ?int $channelTimestamp = null): void
    {
        // JOIN as the bot (UID as source) — same as a client joining; S2S accepts :UID JOIN #channel
        $this->write(sprintf(':%s JOIN %s', $serviceUid, $channelName));
        if ('' !== $maxPrefixLetter && 'q' !== $maxPrefixLetter) {
            $this->setChannelMemberMode($serverSid, $channelName, $serviceUid, $maxPrefixLetter, true, $serviceUid);
        }
    }

    public function setChannelTopic(string $serverSid, string $channelName, ?string $topic, string $serviceUid = '', ?int $channelCreationTs = null): void
    {
        if (null === $topic || '' === $topic) {
            $this->recordWriter->delete('C', $channelName . '::topic');
        } else {
            $this->recordWriter->insert('C', $channelName . '::topic', $topic);
        }
    }

    public function kickFromChannel(string $serverSid, string $channelName, string $targetUid, string $reason, string $serviceUid = ''): void
    {
        $prefix = '' !== $serviceUid ? $serviceUid : $serverSid;
        $this->write(sprintf(':%s KICK %s %s :%s', $prefix, $channelName, $targetUid, $reason));
    }

    public function partChannelAsService(string $serverSid, string $channelName, string $serviceUid): void
    {
        $this->write(sprintf(':%s PART %s', $serviceUid, $channelName));
    }

    /**
     * UnrealUdb DB INS K::G with duration/reason children.
     */
    public function addGline(string $serverSid, string $userMask, string $hostMask, int $duration, string $reason): void
    {
        $mask = $userMask . '@' . $hostMask;
        $this->recordWriter->insert('K', 'G::' . $mask, $reason);
        $this->recordWriter->insert('K', 'G::' . $mask . '::reason', $reason);
        if ($duration > 0) {
            $this->recordWriter->insert('K', 'G::' . $mask . '::duration', '*' . $duration);
        }
    }

    /**
     * UnrealUdb DB DEL K::G.
     */
    public function removeGline(string $serverSid, string $userMask, string $hostMask): void
    {
        $this->recordWriter->delete('K', 'G::' . $userMask . '@' . $hostMask);
    }

    /**
     * UnrealUdb: introduce a temporary pseudo-client with UID.
     * Format: :serverSid UID nick hopcount timestamp ident vhost uid servicestamp umodes * * * :realname
     * Umodes: +B (bot only, not a full service like NickServ).
     */
    public function introducePseudoClient(string $serverSid, string $nick, string $ident, string $vhost, string $uid, string $realname): void
    {
        $ts = time();
        $line = sprintf(
            ':%s UID %s 1 %d %s %s %s 0 +BDIopqR %s * * * :%s',
            $serverSid,
            $nick,
            $ts,
            $ident,
            $vhost,
            $uid,
            $vhost,
            $realname,
        );
        $this->write($line);
    }

    /**
     * UnrealUdb: disconnect a pseudo-client.
     * Format: :uid QUIT :reason.
     */
    public function quitPseudoClient(string $serverSid, string $uid, string $reason): void
    {
        $this->write(sprintf(':%s QUIT :%s', $uid, $reason));
    }

    /**
     * Removes UDB-managed modes from a mode batch:
     * - founder rank 'q' and its UID parameter (UDB owns founder rank via C::<#chan>::founder)
     * - registered channel mode 'r' (UDB manages +r natively on Block C channel profiles)
     * - permanent channel mode 'P' (UDB manages +P natively via C::<#chan>::persistent)
     *
     * @param array<int, string> $params
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function stripUdbManagedModes(string $modeStr, array $params): array
    {
        $filtered = '';
        $filteredParams = [];
        $paramIdx = 0;
        $paramCount = count($params);

        foreach (str_split($modeStr) as $char) {
            if ('+' === $char || '-' === $char) {
                $filtered .= $char;
                continue;
            }

            if ('q' === $char) {
                if ($paramIdx < $paramCount) {
                    ++$paramIdx;
                }

                continue;
            }

            if ('r' === $char || 'P' === $char) {
                continue;
            }

            $filtered .= $char;
            if ($paramIdx < $paramCount) {
                $filteredParams[] = $params[$paramIdx];
                ++$paramIdx;
            }
        }

        if ('' === str_replace(['+', '-'], '', $filtered)) {
            return ['', []];
        }

        return [$filtered, $filteredParams];
    }

    /**
     * @param array<int, string> $params
     */
    private function writeModeLine(string $serverSid, string $channelName, string $modeStr, array $params, string $serviceUid): void
    {
        $prefix = '' !== $serviceUid ? $serviceUid : $serverSid;
        $paramStr = [] === $params ? '' : ' ' . implode(' ', $params);
        $this->write(sprintf(':%s MODE %s %s%s', $prefix, $channelName, $modeStr, $paramStr));
    }

    private function write(string $line): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $this->connectionHolder->writeLine($line);
        $this->logger->debug('> ' . $line);
    }
}
