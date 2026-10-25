<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface EmailDomainPolicy
{
    public function isBlocked(string $email): bool;
}
