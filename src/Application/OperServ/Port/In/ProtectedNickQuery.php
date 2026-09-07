<?php

declare(strict_types=1);

namespace App\Application\OperServ\Port\In;

interface ProtectedNickQuery
{
    public function isRootNickname(string $nickname): bool;

    public function isIrcopNickId(int $nickId): bool;

    public function hasForcedVhost(int $nickId): bool;

    public function resolveForcedVhost(int $nickId, string $nickname): ?string;
}
