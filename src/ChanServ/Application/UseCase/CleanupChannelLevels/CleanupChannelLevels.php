<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\CleanupChannelLevels;

final readonly class CleanupChannelLevels
{
    public function __construct(public int $channelId) {}
}
