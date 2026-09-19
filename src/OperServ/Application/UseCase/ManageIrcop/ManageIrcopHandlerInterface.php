<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageIrcop;

interface ManageIrcopHandlerInterface
{
    public function handle(ManageIrcop $command): ManageIrcopResult;
}
