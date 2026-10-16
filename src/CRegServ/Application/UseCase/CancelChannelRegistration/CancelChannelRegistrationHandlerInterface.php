<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\CancelChannelRegistration;

interface CancelChannelRegistrationHandlerInterface
{
    public function handle(CancelChannelRegistration $command): CancelChannelRegistrationResult;
}
