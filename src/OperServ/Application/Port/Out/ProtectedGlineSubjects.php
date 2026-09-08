<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface ProtectedGlineSubjects
{
    /** @return list<string> */
    public function nicknames(): array;
}
