<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Shared\Application\Port\OperclassServiceActionsInterface;

/**
 * Protocol service actions double implementing both the mandatory surface and
 * the optional operclass capability, recording setUserOperclass calls.
 */
final class RecordingOperclassActions implements ProtocolServiceActionsInterface, OperclassServiceActionsInterface
{
    /** @var list<array{0: string, 1: string, 2: string, 3: ?string}> */
    public array $operclassCalls = [];

    /** @var list<string>|null */
    public ?array $availableOperclasses = null;

    public function setUserOperclass(string $serverSid, string $targetUid, string $targetNickname, ?string $operclass): void
    {
        $this->operclassCalls[] = [$serverSid, $targetUid, $targetNickname, $operclass];
    }

    public function getAvailableOperclasses(): ?array
    {
        return $this->availableOperclasses;
    }

    public function setUserAccount(string $serverSid, string $targetUid, string $accountName): void {}

    public function setUserMode(string $serverSid, string $targetUid, string $modes, array $params = []): void {}

    public function setUserVhost(string $serverSid, string $targetUid, string $vhost, string $cloakedHost = ''): void {}

    public function forceNick(string $serverSid, string $targetUid, string $newNick): void {}

    public function killUser(string $serverSid, string $targetUid, string $reason): void {}

    public function introduceService(string $serverSid, string $nick, string $ident, string $vhost, string $uid, string $realname, string $serviceKey = ''): void {}

    public function setChannelModes(string $serverSid, string $channelName, string $modeStr, array $params = [], string $serviceUid = '', ?int $channelTimestamp = null): void {}

    public function setChannelMemberMode(string $serverSid, string $channelName, string $targetUid, string $modeLetter, bool $add, string $serviceUid = '', ?int $channelTimestamp = null): void {}

    public function inviteUserToChannel(string $serverSid, string $channelName, string $targetUid, string $serviceUid = '', ?int $channelTimestamp = null): void {}

    public function joinChannelAsService(string $serverSid, string $channelName, string $serviceUid, string $maxPrefixLetter, ?int $channelTimestamp = null): void {}

    public function setChannelTopic(string $serverSid, string $channelName, ?string $topic, string $serviceUid = '', ?int $channelCreationTs = null): void {}

    public function kickFromChannel(string $serverSid, string $channelName, string $targetUid, string $reason, string $serviceUid = ''): void {}

    public function partChannelAsService(string $serverSid, string $channelName, string $serviceUid): void {}

    public function addGline(string $serverSid, string $userMask, string $hostMask, int $duration, string $reason): void {}

    public function removeGline(string $serverSid, string $userMask, string $hostMask): void {}

    public function introducePseudoClient(string $serverSid, string $nick, string $ident, string $vhost, string $uid, string $realname): void {}

    public function quitPseudoClient(string $serverSid, string $uid, string $reason): void {}
}
