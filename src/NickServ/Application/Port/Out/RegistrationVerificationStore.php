<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface RegistrationVerificationStore
{
    public function store(string $nickname, string $token, \DateTimeImmutable $expiresAt): void;
}
