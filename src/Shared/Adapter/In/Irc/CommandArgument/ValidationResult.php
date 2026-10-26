<?php

declare(strict_types=1);

namespace App\Shared\Adapter\In\Irc\CommandArgument;

final readonly class ValidationResult
{
    public function __construct(public bool $recognized, public ?string $syntaxKey = null) {}

    public function isValid(): bool
    {
        return $this->recognized && null === $this->syntaxKey;
    }
}
