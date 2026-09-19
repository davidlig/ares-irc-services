<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Domain\ValueObject\ModeChange;

interface ChannelModeActions
{
    /** @param list<ModeChange> $changes */
    public function apply(string $channelName, array $changes): void;
}
