<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SaregisterChannel;

interface SaregisterChannelHandlerInterface
{
    public function handle(SaregisterChannel $command): SaregisterChannelResult;
}
