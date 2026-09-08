<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Persistence;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAuthorityState;

interface UdbAuthorityStateRepositoryInterface
{
    /** Returns false until a validated takeover has approved the local store. */
    public function isApproved(): bool;

    public function state(): UdbAuthorityState;

    public function approve(string $fingerprint): void;

    public function revoke(): void;
}
