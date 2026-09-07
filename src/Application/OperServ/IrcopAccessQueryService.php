<?php

declare(strict_types=1);

namespace App\Application\OperServ;

use App\Application\OperServ\Port\In\IrcopAccessQuery;

final readonly class IrcopAccessQueryService implements IrcopAccessQuery
{
    public function __construct(private IrcopAccessHelper $accessHelper) {}

    public function isRoot(string $nickname): bool
    {
        return $this->accessHelper->isRoot($nickname);
    }

    public function isIrcop(int $nickId, string $nickname): bool
    {
        return $this->accessHelper->isIrcop($nickId, $nickname);
    }

    public function hasPermission(int $nickId, string $nickname, string $permission): bool
    {
        return $this->accessHelper->hasPermission($nickId, $nickname, $permission);
    }

    public function hasAnyPermission(int $nickId, string $nickname, array $permissions): bool
    {
        return $this->accessHelper->hasAnyPermission($nickId, $nickname, $permissions);
    }
}
