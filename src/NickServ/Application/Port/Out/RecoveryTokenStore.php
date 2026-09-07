<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use DateTimeImmutable;

interface RecoveryTokenStore
{
    public function store(string $nickname, string $token, DateTimeImmutable $expiresAt): void;

    public function consume(string $nickname, string $token, DateTimeImmutable $now): bool;

    public function getLastRecoverAt(string $nickname): ?DateTimeImmutable;

    public function recordRecover(string $nickname, DateTimeImmutable $now): void;
}
