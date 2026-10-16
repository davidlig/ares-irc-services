<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\RequestChannelRegistration;

interface RequestChannelRegistrationHandlerInterface
{
    public function handle(RequestChannelRegistration $command): RequestChannelRegistrationResult;
}
