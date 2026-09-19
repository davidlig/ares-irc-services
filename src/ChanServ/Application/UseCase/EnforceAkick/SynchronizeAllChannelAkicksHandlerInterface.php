<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceAkick;

interface SynchronizeAllChannelAkicksHandlerInterface
{
    public function handle(SynchronizeAllChannelAkicks $command): int;
}
