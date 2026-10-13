<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Saregister;

interface SaregisterHandlerInterface
{
    public function handle(Saregister $command): SaregisterResult;
}
