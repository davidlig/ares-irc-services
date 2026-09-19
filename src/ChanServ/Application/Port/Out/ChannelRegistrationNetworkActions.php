<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface ChannelRegistrationNetworkActions
{
    public function supportsRegisteredMode(): bool;

    public function supportsPermanentMode(): bool;

    public function applyRegistrationModes(string $channelName): void;

    public function removeRegistrationModesAfterDrop(string $channelName, string $reason): void;

    public function ensureRegisteredModeOnChannelSync(string $channelName): void;

    /**
     * @param array<string, true> $registeredNames
     * @param list<string>        $eligibleChannelNames
     */
    public function reconcileRegisteredMode(array $registeredNames, array $eligibleChannelNames): void;

    /**
     * @param array<string, true> $registeredNames
     * @param list<string>        $eligibleChannelNames
     */
    public function reconcilePermanentMode(array $registeredNames, array $eligibleChannelNames): void;
}
