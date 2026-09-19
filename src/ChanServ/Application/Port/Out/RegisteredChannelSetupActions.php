<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Domain\ValueObject\ChannelModeLock;

interface RegisteredChannelSetupActions
{
    public function restoreRegistrationModes(string $channelName): void;

    public function restoreModeLock(string $channelName, ChannelModeLock $modeLock): void;

    public function restoreTopic(string $channelName, string $topic): void;

    public function restoreServiceRank(string $channelName): void;
}
