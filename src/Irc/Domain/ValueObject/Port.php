<?php

declare(strict_types=1);

namespace App\Irc\Domain\ValueObject;

use InvalidArgumentException;

use function sprintf;

readonly class Port
{
    public function __construct(public int $value)
    {
        if ($value < 1 || $value > 65535) {
            throw new InvalidArgumentException(sprintf('Port %d is out of the valid range (1–65535).', $value));
        }
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
