<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\DenyChannelRegistration;

interface DenyChannelRegistrationHandlerInterface
{
    public function handle(DenyChannelRegistration $command): DenyChannelRegistrationResult;
}
