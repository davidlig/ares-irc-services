<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Domain\ValueObject\RegistrationMode;

interface RegistrationModeQuery
{
    public function current(): RegistrationMode;
}
