<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface ForcedVhostCheckerInterface
{
    public function hasForcedVhost(int $nickId): bool;

    public function resolveForcedVhost(int $nickId, string $nickname): ?string;
}
