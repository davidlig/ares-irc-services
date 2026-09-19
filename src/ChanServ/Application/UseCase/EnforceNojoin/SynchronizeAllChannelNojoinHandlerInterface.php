<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceNojoin;

interface SynchronizeAllChannelNojoinHandlerInterface
{
    public function handle(): int;
}
