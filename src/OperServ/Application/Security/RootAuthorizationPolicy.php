<?php

declare(strict_types=1);

namespace App\OperServ\Application\Security;

use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;

final readonly class RootAuthorizationPolicy
{
    public function __construct(private RootIdentityRegistry $rootIdentities) {}

    public function allows(OperatorActor $actor): bool
    {
        return $actor->identified
            && null !== $actor->identifiedAccountId
            && $this->rootIdentities->contains($actor->nickname);
    }

    public function protectsNickname(string $nickname): bool
    {
        return $this->rootIdentities->contains($nickname);
    }

    /** @return list<string> */
    public function protectedNicknames(): array
    {
        return $this->rootIdentities->allNicknames();
    }
}
