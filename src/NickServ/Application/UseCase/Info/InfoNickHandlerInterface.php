<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Info;

interface InfoNickHandlerInterface
{
    public function handle(InfoNick $command): InfoNickResult;
}
