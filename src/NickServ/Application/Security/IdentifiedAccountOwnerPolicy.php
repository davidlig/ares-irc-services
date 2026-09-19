<?php

declare(strict_types=1);

namespace App\NickServ\Application\Security;

final class IdentifiedAccountOwnerPolicy
{
    public function allows(bool $identified, ?int $actorAccountId, int $ownerAccountId): bool
    {
        return $identified && null !== $actorAccountId && $ownerAccountId === $actorAccountId;
    }
}
