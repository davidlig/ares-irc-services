<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Domain\ValueObject\RegistrationMode;

interface RegistrationModeQuery
{
    public function current(): RegistrationMode;
}
