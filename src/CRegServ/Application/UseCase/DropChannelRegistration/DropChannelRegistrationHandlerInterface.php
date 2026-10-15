<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\DropChannelRegistration;

interface DropChannelRegistrationHandlerInterface
{
    public function handle(DropChannelRegistration $command): DropChannelRegistrationResult;
}
