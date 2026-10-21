<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use DateTimeImmutable;

interface TemporaryRescueTokenStore
{
    public function replace(int $accountId, string $hash, DateTimeImmutable $expiresAt): void;

    public function matches(int $accountId, string $hash, DateTimeImmutable $now): bool;

    public function removeIfMatches(int $accountId, string $hash): void;

    public function prune(DateTimeImmutable $now): int;

    public function discard(int $accountId): void;
}
