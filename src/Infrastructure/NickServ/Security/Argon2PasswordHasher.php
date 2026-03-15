<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Security;

use App\Domain\NickServ\Service\PasswordHasherInterface;
use RuntimeException;

use const PASSWORD_ARGON2ID;

final readonly class Argon2PasswordHasher implements PasswordHasherInterface
{
    public function hash(string $plainPassword): string
    {
        $hash = password_hash($plainPassword, PASSWORD_ARGON2ID);

        // @codeCoverageIgnoreStart
        // Cannot test password_hash failure in unit tests.
        // Returns false only on memory exhaustion or invalid algo constant.
        // PASSWORD_ARGON2ID is always valid, and memory exhaustion would kill the process.
        if (false === $hash) {
            throw new RuntimeException('Password hashing failed.');
        }
        // @codeCoverageIgnoreEnd

        return $hash;
    }

    public function verify(string $plainPassword, string $hash): bool
    {
        return password_verify($plainPassword, $hash);
    }
}
