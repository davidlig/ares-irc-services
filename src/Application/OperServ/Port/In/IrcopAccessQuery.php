<?php

declare(strict_types=1);

namespace App\Application\OperServ\Port\In;

interface IrcopAccessQuery
{
    public function isRoot(string $nickname, ?int $accountId, bool $identified, bool $ircOperator): bool;

    public function isIrcop(string $nickname, ?int $accountId, bool $identified, bool $ircOperator): bool;

    public function hasPermission(string $nickname, ?int $accountId, bool $identified, bool $ircOperator, string $permission): bool;

    /** @param list<string> $permissions */
    public function hasAnyPermission(string $nickname, ?int $accountId, bool $identified, bool $ircOperator, array $permissions): bool;
}
