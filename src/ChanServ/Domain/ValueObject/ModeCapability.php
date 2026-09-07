<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

final readonly class ModeCapability
{
    public function __construct(
        public ModeName $mode,
        public bool $parameterRequiredWhenSet = false,
        public bool $parameterRequiredWhenUnset = false,
        public bool $protected = false,
    ) {}
}
