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

    private function write(string $line): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $this->connectionHolder->writeLine($line);
        $this->logger->debug('> ' . $line);
    }
}
