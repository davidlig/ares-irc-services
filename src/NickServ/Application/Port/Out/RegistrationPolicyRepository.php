<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Domain\ValueObject\RegistrationMode;

interface RegistrationPolicyRepository extends RegistrationModeQuery
{
    /** Changes policy atomically and returns the previous mode. */
    public function change(RegistrationMode $mode): RegistrationMode;
}
