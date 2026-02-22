<?php

declare(strict_types=1);

namespace App\Domain\IRC\ValueObject;

use InvalidArgumentException;

readonly class LinkPassword
{
    public function __construct(public readonly string $value)
    {
        if ('' === $value) {
            throw new InvalidArgumentException('Link password cannot be empty.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
