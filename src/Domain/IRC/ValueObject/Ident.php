<?php

declare(strict_types=1);

namespace App\Domain\IRC\ValueObject;

use InvalidArgumentException;

/**
 * IRC username/ident (the part before @ in user@host).
 */
readonly class Ident
{
    public function __construct(public readonly string $value)
    {
        if ('' === $value) {
            throw new InvalidArgumentException('Ident cannot be empty.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
