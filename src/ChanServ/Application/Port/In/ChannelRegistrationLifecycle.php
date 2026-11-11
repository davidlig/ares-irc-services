<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

interface ChannelRegistrationLifecycle
{
    public function channelRegistered(string $channelName): void;

    public function channelDropped(string $channelName, string $reason): void;

    public function channelSynchronized(string $channelName): void;

    public function reconcileRegisteredMode(): void;

    public function reconcilePermanentMode(): void;

    /**
     * @param array<string, true> $registeredNames
     * @param list<string>        $eligibleChannelNames
     */
    public function reconcileKnownChannels(array $registeredNames, array $eligibleChannelNames): void;
}
