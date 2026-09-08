<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface ForcedVhostPolicy
{
    public function isValid(string $pattern): bool;
}
