<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\CleanupChannelHistory;

final readonly class CleanupChannelHistory
{
    public function __construct(public int $channelId) {}
}
