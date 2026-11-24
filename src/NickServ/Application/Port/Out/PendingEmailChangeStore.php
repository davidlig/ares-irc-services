<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface PendingEmailChangeStore
{
    public function store(string $nickname, string $newEmail, string $token, \DateTimeImmutable $now): void;

    public function consume(string $nickname, string $newEmail, string $token, \DateTimeImmutable $now): bool;
}
