<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\Unban;

interface UnbanChannelHandlerInterface
{
    public function handle(UnbanChannel $command): UnbanChannelResult;
}
