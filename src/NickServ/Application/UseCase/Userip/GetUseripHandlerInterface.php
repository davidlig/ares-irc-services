<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Userip;

interface GetUseripHandlerInterface
{
    public function handle(GetUserip $command): GetUseripResult;
}
