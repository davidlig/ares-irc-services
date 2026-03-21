<?php

declare(strict_types=1);

namespace App\Domain\IRC\ValueObject;

use InvalidArgumentException;

use function sprintf;

/**
 * Represents an IRC user mask in the format nick!user@host.
 * Used for AKICK matching and user identification.
 */
readonly class UserMask
{
    private function __construct(public readonly string $value)
    {
        if ('' === $value) {
            throw new InvalidArgumentException('UserMask cannot be empty.');
        }
    }

    public static function fromParts(string $nick, string $ident, string $host): self
    {
        return new self(sprintf('%s!%s@%s', $nick, $ident, $host));
    }

    public static function fromString(string $mask): self
    {
        return new self($mask);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
