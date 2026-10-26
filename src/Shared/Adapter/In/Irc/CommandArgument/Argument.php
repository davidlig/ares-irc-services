<?php

declare(strict_types=1);

namespace App\Shared\Adapter\In\Irc\CommandArgument;

/** A transport argument, not a business-value validation rule. */
final readonly class Argument
{
    /** @param list<string> $alternatives Case-insensitive literal alternatives. */
    public function __construct(
        public string $name,
        public bool $optional = false,
        public bool $rest = false,
        public array $alternatives = [],
    ) {}
}
