<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageMotd;

interface ManageMotdHandlerInterface
{
    public function handle(ManageMotd $command): ManageMotdResult;
}
