<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Rename;

interface RenameNickHandlerInterface
{
    public function handle(RenameNick $command): RenameNickResult;
}
