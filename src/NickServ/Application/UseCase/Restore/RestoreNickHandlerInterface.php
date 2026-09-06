<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Restore;

interface RestoreNickHandlerInterface
{
    public function handle(RestoreNick $command): RestoreNickResult;
}
