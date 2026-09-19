<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ClearAccess;

final readonly class ClearChannelAccess
{
    public function __construct(public string $channelName) {}
}
