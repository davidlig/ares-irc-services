<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Security;

use App\Application\OperServ\Port\In\ProtectedNickQuery;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;

final readonly class OperIrcopForcedVhostChecker implements ForcedVhostCheckerInterface
{
    public function __construct(private ProtectedNickQuery $protectedNickQuery) {}

    public function hasForcedVhost(int $nickId): bool
    {
        return $this->protectedNickQuery->hasForcedVhost($nickId);
    }

    public function resolveForcedVhost(int $nickId, string $nickname): ?string
    {
        return $this->protectedNickQuery->resolveForcedVhost($nickId, $nickname);
    }
}
