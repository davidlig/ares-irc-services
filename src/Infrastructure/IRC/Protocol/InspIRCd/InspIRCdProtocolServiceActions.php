<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Application\Port\ProtocolServiceActionsInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function in_array;
use function sprintf;

/**
 * InspIRCd v4 services: METADATA (account, accountnicks), MODE (+r/-r), SVSNICK, KILL, FMODE.
 *
 * InspIRCd does NOT have SVS2MODE or SVSMODE — those are UnrealIRCd commands
 * that cause a ProtocolException on InspIRCd. User identification is done via
 * METADATA keys (accountid, accountname, accountnicks). InspIRCd 4.x does NOT
 * automatically set +r from METADATA — services must send an explicit MODE +r/-r
 * after setting/clearing the account metadata.
 *
 * InspIRCd v4 (protocol 1205+) uses ADDLINE/DELLINE instead of GLINE/QLINE, and
 * FTOPIC instead of TOPIC. KICK does NOT include a membership ID parameter —
 * omitting it causes InspIRCd to skip membership ID validation, which is what
 * services need since they do not track the internal Membership::id value.
 */
final readonly class InspIRCdProtocolServiceActions implements ProtocolServiceActionsInterface
{
    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function setUserAccount(string $serverSid, string $targetUid, string $accountName): void
    {
        if ('0' === $accountName) {
            $this->write(sprintf(':%s METADATA %s accountid :', $serverSid, $targetUid));
            $this->write(sprintf(':%s METADATA %s accountname :', $serverSid, $targetUid));
            $this->write(sprintf(':%s METADATA %s accountnicks :', $serverSid, $targetUid));
            $this->write(sprintf(':%s MODE %s -r', $serverSid, $targetUid));

            return;
        }

        $this->write(sprintf(':%s METADATA %s accountid :%s', $serverSid, $targetUid, $accountName));
        $this->write(sprintf(':%s METADATA %s accountname :%s', $serverSid, $targetUid, $accountName));
        $this->write(sprintf(':%s METADATA %s accountnicks :%s', $serverSid, $targetUid, $accountName));
        $this->write(sprintf(':%s MODE %s +r', $serverSid, $targetUid));
    }

    public function setUserMode(string $serverSid, string $targetUid, string $modes): void
    {
        $this->write(sprintf(':%s MODE %s %s', $serverSid, $targetUid, $modes));
    }

    public function forceNick(string $serverSid, string $targetUid, string $newNick): void
    {
        $this->write(sprintf(':%s SVSNICK %s %s %d', $serverSid, $targetUid, $newNick, time()));
    }

    public function killUser(string $serverSid, string $targetUid, string $reason): void
    {
        $this->write(sprintf(':%s KILL %s :%s', $serverSid, $targetUid, $reason));
    }

    public function setChannelModes(string $serverSid, string $channelName, string $modeStr, array $params = [], string $serviceUid = '', ?int $channelTimestamp = null): void
    {
        $prefix = '' !== $serviceUid ? $serviceUid : $serverSid;

        if (null !== $channelTimestamp) {
            $paramStr = [] === $params ? '' : ' ' . implode(' ', $params);
            $this->write(sprintf(':%s FMODE %s %d %s%s', $prefix, $channelName, $channelTimestamp, $modeStr, $paramStr));

            return;
        }

        $paramStr = [] === $params ? '' : ' ' . implode(' ', $params);
        $this->write(sprintf(':%s MODE %s %s%s', $prefix, $channelName, $modeStr, $paramStr));
    }

    public function setChannelMemberMode(string $serverSid, string $channelName, string $targetUid, string $modeLetter, bool $add, string $serviceUid = '', ?int $channelTimestamp = null): void
    {
        $prefix = '' !== $serviceUid ? $serviceUid : $serverSid;
        $delta = $add ? '+' . $modeLetter : '-' . $modeLetter;

        if (null !== $channelTimestamp) {
            $this->write(sprintf(':%s FMODE %s %d %s %s', $prefix, $channelName, $channelTimestamp, $delta, $targetUid));

            return;
        }

        $this->write(sprintf(':%s MODE %s %s %s', $prefix, $channelName, $delta, $targetUid));
    }

    public function inviteUserToChannel(string $serverSid, string $channelName, string $targetUid, string $serviceUid = ''): void
    {
        $prefix = '' !== $serviceUid ? $serviceUid : $serverSid;
        $this->write(sprintf(':%s INVITE %s %s %d', $prefix, $targetUid, $channelName, time()));
    }

    public function joinChannelAsService(string $serverSid, string $channelName, string $serviceUid, string $maxPrefixLetter, ?int $channelTimestamp = null): void
    {
        $timestamp = (string) ($channelTimestamp ?? time());
        $prefixLetter = strtolower($maxPrefixLetter);
        if ('' === $prefixLetter || !in_array($prefixLetter, ['v', 'h', 'o', 'a', 'q'], true)) {
            $prefixLetter = 'o';
        }
        $memberEntry = $prefixLetter . ',' . $serviceUid . ':';
        $this->write(sprintf(':%s FJOIN %s %s 0 :%s', $serverSid, $channelName, $timestamp, $memberEntry));
    }

    public function setChannelTopic(string $serverSid, string $channelName, ?string $topic, string $serviceUid = '', ?int $channelCreationTs = null): void
    {
        $prefix = '' !== $serviceUid ? $serviceUid : $serverSid;
        $creationTs = $channelCreationTs ?? time();
        $setTs = time();
        if (null === $topic) {
            $this->write(sprintf(':%s FTOPIC %s %d %d :', $prefix, $channelName, $creationTs, $setTs));
        } else {
            $this->write(sprintf(':%s FTOPIC %s %d %d :%s', $prefix, $channelName, $creationTs, $setTs, $topic));
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
     * InspIRCd v4 ADDLINE G command.
     * Format: :serverSid ADDLINE G user@host serverSid timestamp duration :reason
     * Duration in seconds, 0 = permanent.
     */
    public function addGline(string $serverSid, string $userMask, string $hostMask, int $duration, string $reason): void
    {
        $mask = $userMask . '@' . $hostMask;
        $this->write(sprintf(':%s ADDLINE G %s %s %d %d :%s', $serverSid, $mask, $serverSid, time(), $duration, $reason));
    }

    /**
     * InspIRCd v4 DELLINE G command.
     * Format: :serverSid DELLINE G user@host.
     */
    public function removeGline(string $serverSid, string $userMask, string $hostMask): void
    {
        $mask = $userMask . '@' . $hostMask;
        $this->write(sprintf(':%s DELLINE G %s', $serverSid, $mask));
    }

    /**
     * InspIRCd: introduce a temporary pseudo-client with UID.
     * Format (1206+): :serverSid UID uuid ts nick real_host displayed_host real_user displayed_user ip connect_time modes :realname
     * Umodes: +B (bot only, not a full service like NickServ).
     */
    public function introducePseudoClient(string $serverSid, string $nick, string $ident, string $vhost, string $uid, string $realname): void
    {
        $ts = time();
        $line = sprintf(
            ':%s UID %s %d %s %s %s %s %s 0.0.0.0 %d +B :%s',
            $serverSid,
            $uid,
            $ts,
            $nick,
            $vhost,
            $vhost,
            $ident,
            $ident,
            $ts,
            $realname,
        );
        $this->write($line);
    }

    /**
     * InspIRCd: disconnect a pseudo-client.
     * Format: :uid QUIT :reason.
     */
    public function quitPseudoClient(string $serverSid, string $uid, string $reason): void
    {
        $this->write(sprintf(':%s QUIT :%s', $uid, $reason));
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
