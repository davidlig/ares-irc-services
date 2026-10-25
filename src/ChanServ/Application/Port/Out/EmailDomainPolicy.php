<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface EmailDomainPolicy
{
    public function isBlocked(string $email): bool;
}
