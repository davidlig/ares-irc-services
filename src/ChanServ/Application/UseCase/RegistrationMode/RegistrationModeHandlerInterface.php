<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\RegistrationMode;

use App\ChanServ\Domain\ValueObject\RegistrationMode;

interface RegistrationModeHandlerInterface
{
    public function current(): RegistrationMode;

    public function change(RegistrationMode $mode, string $operatorNickname): RegistrationMode;
}
