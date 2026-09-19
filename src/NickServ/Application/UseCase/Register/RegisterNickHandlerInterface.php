<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Register;

interface RegisterNickHandlerInterface
{
    public function handle(RegisterNick $command): RegisterNickResult;
}
