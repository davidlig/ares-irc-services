<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Security;

use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\OperServ\Application\Port\In\ProtectedNickQuery;

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
