<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\RegisterChannel;

interface RegisterChannelHandlerInterface
{
    public function handle(RegisterChannel $command): RegisterChannelResult;
}
