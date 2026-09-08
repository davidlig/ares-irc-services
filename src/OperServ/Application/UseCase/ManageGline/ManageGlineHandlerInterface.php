<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGline;

interface ManageGlineHandlerInterface
{
    public function handle(ManageGline $command): ManageGlineResult;
}
