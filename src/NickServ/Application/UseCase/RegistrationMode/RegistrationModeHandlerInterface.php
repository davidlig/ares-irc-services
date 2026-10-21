<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\RegistrationMode;

interface RegistrationModeHandlerInterface
{
    public function handle(RegistrationModeInput $input): RegistrationModeResult;
}
