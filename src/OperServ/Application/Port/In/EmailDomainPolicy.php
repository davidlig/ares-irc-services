<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In;

/** Read-only policy query for introducing an email address, not existing-address delivery. */
interface EmailDomainPolicy
{
    public function isBlocked(string $email): bool;
}
