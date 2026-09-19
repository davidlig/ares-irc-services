<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Security;

use App\NickServ\Application\Port\Out\PasswordHasher;
use RuntimeException;

use const PASSWORD_BCRYPT;

final readonly class PhpPasswordHasher implements PasswordHasher
{
    private const int BCRYPT_COST = 12;

    public function hash(string $plainPassword): string
    {
        $hash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);

        // @codeCoverageIgnoreStart
        // Cannot test password_hash failure in unit tests.
        // Returns false only on memory exhaustion or invalid algo constant.
        // PASSWORD_BCRYPT is always valid, and memory exhaustion would kill the process.
        // @phpstan-ignore identical.alwaysFalse
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
