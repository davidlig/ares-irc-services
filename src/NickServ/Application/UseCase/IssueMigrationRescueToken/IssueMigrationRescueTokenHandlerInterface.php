<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\IssueMigrationRescueToken;

interface IssueMigrationRescueTokenHandlerInterface
{
    public function handle(IssueMigrationRescueToken $command): void;
}
