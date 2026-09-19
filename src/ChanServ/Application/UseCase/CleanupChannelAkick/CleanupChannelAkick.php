<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\CleanupChannelAkick;

final readonly class CleanupChannelAkick
{
    public function __construct(public int $channelId) {}
}
