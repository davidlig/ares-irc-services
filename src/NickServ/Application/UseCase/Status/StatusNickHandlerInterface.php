<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Status;

interface StatusNickHandlerInterface
{
    public function handle(StatusNick $command): StatusNickResult;
}
