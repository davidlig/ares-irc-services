<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Service;

/**
 * Port for hashing and verifying passwords.
 * Implementation (e.g. Argon2id) lives in Infrastructure.
 */
interface PasswordHasherInterface
{
    public function hash(string $plainPassword): string;

    public function verify(string $plainPassword, string $hash): bool;
}
