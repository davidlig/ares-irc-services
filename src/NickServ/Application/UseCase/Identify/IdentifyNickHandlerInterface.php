<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Identify;

interface IdentifyNickHandlerInterface
{
    public function handle(IdentifyNick $command): IdentifyNickResult;
}
