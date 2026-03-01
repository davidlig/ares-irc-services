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

    private function write(string $line): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $this->connectionHolder->writeLine($line);
        $this->logger->debug('> ' . $line);
    }
}
