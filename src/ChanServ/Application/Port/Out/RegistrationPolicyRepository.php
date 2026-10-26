<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Domain\ValueObject\RegistrationMode;

interface RegistrationPolicyRepository extends RegistrationModeQuery
{
    /** Changes policy atomically and returns the previous mode. */
    public function change(RegistrationMode $mode): RegistrationMode;
}
