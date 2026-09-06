<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Recover;

interface RecoverNickHandlerInterface
{
    public function handle(RecoverNick $command): RecoverNickResult;
}
