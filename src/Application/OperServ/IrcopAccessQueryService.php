<?php

declare(strict_types=1);

namespace App\Application\OperServ;

use App\Application\OperServ\Port\In\IrcopAccessQuery;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;

final readonly class IrcopAccessQueryService implements IrcopAccessQuery
{
    public function __construct(private OperatorAuthorizationQuery $authorization) {}

    public function isRoot(string $nickname, ?int $accountId, bool $identified, bool $ircOperator): bool
    {
        return $this->authorization->root($this->actor($nickname, $accountId, $identified, $ircOperator))->granted;
    }

    public function isIrcop(string $nickname, ?int $accountId, bool $identified, bool $ircOperator): bool
    {
        return $this->authorization->ircOperator($this->actor($nickname, $accountId, $identified, $ircOperator))->granted;
    }

    public function hasPermission(string $nickname, ?int $accountId, bool $identified, bool $ircOperator, string $permission): bool
    {
        return $this->authorization->permission($this->actor($nickname, $accountId, $identified, $ircOperator), $permission)->granted;
    }

    public function hasAnyPermission(string $nickname, ?int $accountId, bool $identified, bool $ircOperator, array $permissions): bool
    {
        return array_any(
            $permissions,
            fn (string $permission): bool => $this->hasPermission($nickname, $accountId, $identified, $ircOperator, $permission),
        );
    }

    private function actor(string $nickname, ?int $accountId, bool $identified, bool $ircOperator): OperatorActor
    {
        return new OperatorActor($nickname, $accountId, $identified, $ircOperator);
    }
}
