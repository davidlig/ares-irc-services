<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface ChanServOperatorAccess
{
    public function isRoot(string $nickname): bool;

    public function isIrcop(int $nickId, string $nickname): bool;

    public function hasPermission(int $nickId, string $nickname, string $permission): bool;

    /** @param list<string> $permissions */
    public function hasAnyPermission(int $nickId, string $nickname, array $permissions): bool;
}
