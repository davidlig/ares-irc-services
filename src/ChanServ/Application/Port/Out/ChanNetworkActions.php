<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

/**
 * Network capabilities required by ChanServ application workflows.
 */
interface ChanNetworkActions
{
    /**
     * @param list<string> $modeParams
     */
    public function setChannelModes(
        string $channelName,
        string $modeString,
        array $modeParams = [],
        ?int $channelCreationTime = null,
    ): void;

    public function joinChannelAsService(string $channelName, ?int $channelCreationTime = null): void;

    public function kickFromChannel(string $channelName, string $targetUid, string $reason): void;

    public function isChannelOnNetwork(string $channelName): bool;

    public function getChannelTimestamp(string $channelName): ?int;

    /**
     * @return list<string>
     */
    public function getChannelMemberUids(string $channelName): array;

    public function removeRegistrationModes(string $channelName): void;

    public function restoreRegistrationModes(string $channelName): void;
}
