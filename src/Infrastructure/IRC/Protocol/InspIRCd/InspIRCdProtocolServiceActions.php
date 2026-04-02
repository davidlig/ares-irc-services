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
 * InspIRCd services: SVS2MODE (+r account), SVSMODE, SVSNICK, KILL.
 * Wire format matches InspIRCd server protocol (same style as Unreal).
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
        $modeDelta = ('0' === $accountName) ? '-r' : '+r';
        $this->write(sprintf(':%s SVS2MODE %s %s', $serverSid, $targetUid, $modeDelta));
    }

    public function setUserMode(string $serverSid, string $targetUid, string $modes): void
    {
        $this->write(sprintf(':%s SVSMODE %s %s', $serverSid, $targetUid, $modes));
    }

    public function forceNick(string $serverSid, string $targetUid, string $newNick): void
    {
        $this->write(sprintf(':%s SVSNICK %s %s %d', $serverSid, $targetUid, $newNick, time()));
    }

    public function killUser(string $serverSid, string $targetUid, string $reason): void
    {
        $this->write(sprintf(':%s KILL %s :%s', $serverSid, $targetUid, $reason));
    }

    public function setChannelModes(string $serverSid, string $channelName, string $modeStr, array $params = [], string $serviceUid = ''): void
    {
        $prefix = '' !== $serviceUid ? $serviceUid : $serverSid;
        $paramStr = [] === $params ? '' : ' ' . implode(' ', $params);
        $this->write(sprintf(':%s MODE %s %s%s', $prefix, $channelName, $modeStr, $paramStr));
    }

    public function setChannelMemberMode(string $serverSid, string $channelName, string $targetUid, string $modeLetter, bool $add, string $serviceUid = ''): void
    {
        $prefix = '' !== $serviceUid ? $serviceUid : $serverSid;
        $delta = $add ? '+' . $modeLetter : '-' . $modeLetter;
        $this->write(sprintf(':%s MODE %s %s %s', $prefix, $channelName, $delta, $targetUid));
    }

    public function inviteUserToChannel(string $serverSid, string $channelName, string $targetUid, string $serviceUid = ''): void
    {
        $prefix = '' !== $serviceUid ? $serviceUid : $serverSid;
        $this->write(sprintf(':%s INVITE %s %s', $prefix, $targetUid, $channelName));
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

    public function setChannelTopic(string $serverSid, string $channelName, ?string $topic, string $serviceUid = ''): void
    {
        $prefix = '' !== $serviceUid ? $serviceUid : $serverSid;
        $trailing = null === $topic ? '' : ' :' . $topic;
        $this->write(sprintf(':%s TOPIC %s%s', $prefix, $channelName, $trailing));
    }

    public function kickFromChannel(string $serverSid, string $channelName, string $targetUid, string $reason, string $serviceUid = ''): void
    {
        $prefix = '' !== $serviceUid ? $serviceUid : $serverSid;
        $this->write(sprintf(':%s KICK %s %s :%s', $prefix, $channelName, $targetUid, $reason));
    }

    /**
     * InspIRCd GLINE command.
     * Format: :serverSid GLINE user@host duration :reason
     * Duration in seconds, 0 = permanent.
     */
    public function addGline(string $serverSid, string $userMask, string $hostMask, int $duration, string $reason): void
    {
        $mask = $userMask . '@' . $hostMask;
        $this->write(sprintf(':%s GLINE %s %d :%s', $serverSid, $mask, $duration, $reason));
    }

    /**
     * InspIRCd GLINE removal.
     * Format: :serverSid GLINE user@host !duration (negative duration to remove).
     */
    public function removeGline(string $serverSid, string $userMask, string $hostMask): void
    {
        $mask = $userMask . '@' . $hostMask;
        $this->write(sprintf(':%s GLINE %s !*', $serverSid, $mask));
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
            ':%s UID %s %d %s %s %s %s %s * %d +B :%s',
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
