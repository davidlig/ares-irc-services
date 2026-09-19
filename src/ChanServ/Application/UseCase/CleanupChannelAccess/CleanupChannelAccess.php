<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\CleanupChannelAccess;

final readonly class CleanupChannelAccess
{
    public function __construct(public int $channelId) {}
}
