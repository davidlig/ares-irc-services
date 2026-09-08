<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface RootIdentityRegistry
{
    public function contains(string $nickname): bool;

    /** @return list<string> */
    public function allNicknames(): array;
}
