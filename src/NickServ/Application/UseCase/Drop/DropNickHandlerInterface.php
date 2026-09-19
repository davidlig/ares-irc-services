<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Drop;

interface DropNickHandlerInterface
{
    public function handle(DropNick $command): DropNickResult;
}
