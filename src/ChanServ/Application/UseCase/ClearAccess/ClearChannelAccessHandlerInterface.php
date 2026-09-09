<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ClearAccess;

interface ClearChannelAccessHandlerInterface
{
    public function handle(ClearChannelAccess $command): ClearChannelAccessResult;
}
