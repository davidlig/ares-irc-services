<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Security;

use App\Domain\NickServ\Service\PasswordHasherInterface;
use RuntimeException;

use const PASSWORD_DEFAULT;

final readonly class PhpPasswordHasher implements PasswordHasherInterface
{
    public function hash(string $plainPassword): string
    {
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

        // @codeCoverageIgnoreStart
        // Cannot test password_hash failure in unit tests.
        // Returns false only on memory exhaustion or invalid algo constant.
        // PASSWORD_DEFAULT is always valid, and memory exhaustion would kill the process.
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
