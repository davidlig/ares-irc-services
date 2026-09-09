<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\RemoveOwnAccess;

interface RemoveOwnChannelAccessHandlerInterface
{
    public function handle(RemoveOwnChannelAccess $command): RemoveOwnChannelAccessOutcome;
}
