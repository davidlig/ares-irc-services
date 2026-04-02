<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\Unreal;

use App\Application\Port\ProtocolServiceActionsInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;

/**
 * UnrealIRCd: SVS2MODE (+r account), SVSMODE, SVSNICK, KILL.
 *
 * Service join: send JOIN with UID as source (RFC 2813 / client-style join on S2S link).
 * Then set member mode (+q/+o etc) so the bot gets the desired privilege.
 */
final readonly class UnrealIRCdProtocolServiceActions implements ProtocolServiceActionsInterface
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
        // JOIN as the bot (UID as source) — same as a client joining; S2S accepts :UID JOIN #channel
        $this->write(sprintf(':%s JOIN %s', $serviceUid, $channelName));
        if ('' !== $maxPrefixLetter) {
            $this->setChannelMemberMode($serverSid, $channelName, $serviceUid, $maxPrefixLetter, true, $serviceUid);
        }
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
     * UnrealIRCd TKL + G for G-lines.
     * Format: TKL + G user host set_by expire_timestamp set_at_timestamp :reason
     * Duration 0 = permanent (no expiry).
     */
    public function addGline(string $serverSid, string $userMask, string $hostMask, int $duration, string $reason): void
    {
        $setBy = $serverSid;
        $expireTimestamp = 0 === $duration ? 0 : time() + $duration;
        $setAtTimestamp = time();
        $this->write(sprintf(
            'TKL + G %s %s %s %d %d :%s',
            $userMask,
            $hostMask,
            $setBy,
            $expireTimestamp,
            $setAtTimestamp,
            $reason,
        ));
    }

    /**
     * UnrealIRCd TKL - G for removing G-lines.
     * Format: TKL - G user host set_by.
     */
    public function removeGline(string $serverSid, string $userMask, string $hostMask): void
    {
        $this->write(sprintf(
            'TKL - G %s %s %s',
            $userMask,
            $hostMask,
            $serverSid,
        ));
    }

    /**
     * UnrealIRCd: introduce a temporary pseudo-client with UID.
     * Format: :serverSid UID nick hopcount timestamp ident vhost uid servicestamp umodes * * * :realname
     * Umodes: +B (bot only, not a full service like NickServ).
     */
    public function introducePseudoClient(string $serverSid, string $nick, string $ident, string $vhost, string $uid, string $realname): void
    {
        $ts = time();
        $line = sprintf(
            ':%s UID %s 1 %d %s %s %s 0 +B %s * * * :%s',
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

    private function write(string $line): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $this->connectionHolder->writeLine($line);
        $this->logger->debug('> ' . $line);
    }
}
