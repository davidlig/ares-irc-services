<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Saregister;

interface SaregisterNickHandlerInterface
{
    public function handle(SaregisterNick $command): SaregisterNickResult;
}
