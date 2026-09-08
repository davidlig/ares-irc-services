<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageRole;

interface ManageRoleHandlerInterface
{
    public function handle(ManageRole $command): ManageRoleResult;
}
