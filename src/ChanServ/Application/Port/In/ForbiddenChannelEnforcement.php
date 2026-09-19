<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

interface ForbiddenChannelEnforcement
{
    public function enforcePublishedForbiddenChannel(string $channelName): void;

    public function releaseUnforbiddenChannel(string $channelName): void;

    public function enforceAllForbiddenChannels(): void;

    public function enforceForbiddenUserJoin(string $channelName, string $userUid): void;

    public function enforceConfiguredForbiddenChannel(string $channelName): void;
}
