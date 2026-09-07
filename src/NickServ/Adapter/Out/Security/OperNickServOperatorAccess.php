<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Security;

use App\Application\OperServ\Port\In\IrcopAccessQuery;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;

final readonly class OperNickServOperatorAccess implements NickServOperatorAccess
{
    public function __construct(private IrcopAccessQuery $query) {}

    public function isRoot(string $nickname): bool
    {
        return $this->query->isRoot($nickname);
    }

    public function isIrcop(int $nickId, string $nickname): bool
    {
        return $this->query->isIrcop($nickId, $nickname);
    }

    public function hasPermission(int $nickId, string $nickname, string $permission): bool
    {
        return $this->query->hasPermission($nickId, $nickname, $permission);
    }

    public function hasAnyPermission(int $nickId, string $nickname, array $permissions): bool
    {
        return $this->query->hasAnyPermission($nickId, $nickname, $permissions);
    }
}
