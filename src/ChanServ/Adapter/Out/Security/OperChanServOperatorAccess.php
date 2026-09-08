<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Security;

use App\Application\OperServ\Port\In\IrcopAccessQuery;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;

final readonly class OperChanServOperatorAccess implements ChanServOperatorAccess
{
    public function __construct(private IrcopAccessQuery $query) {}

    public function isRoot(string $nickname, ?int $accountId, bool $identified, bool $ircOperator): bool
    {
        return $this->query->isRoot($nickname, $accountId, $identified, $ircOperator);
    }

    public function isIrcop(string $nickname, ?int $accountId, bool $identified, bool $ircOperator): bool
    {
        return $this->query->isIrcop($nickname, $accountId, $identified, $ircOperator);
    }

    public function hasPermission(string $nickname, ?int $accountId, bool $identified, bool $ircOperator, string $permission): bool
    {
        return $this->query->hasPermission($nickname, $accountId, $identified, $ircOperator, $permission);
    }

    public function hasAnyPermission(string $nickname, ?int $accountId, bool $identified, bool $ircOperator, array $permissions): bool
    {
        return $this->query->hasAnyPermission($nickname, $accountId, $identified, $ircOperator, $permissions);
    }
}
