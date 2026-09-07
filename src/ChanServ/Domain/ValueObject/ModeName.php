<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

use InvalidArgumentException;

final readonly class ModeName
{
    public function __construct(public string $value)
    {
        if ('' === $value) {
            throw new InvalidArgumentException('Mode name cannot be empty.');
        }
    }
}
