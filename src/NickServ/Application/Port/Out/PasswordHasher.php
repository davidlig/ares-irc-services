<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface PasswordHasher
{
    public function hash(string $plainPassword): string;

    public function verify(string $plainPassword, string $passwordHash): bool;
}
