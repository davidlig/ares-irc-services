<?php

declare(strict_types=1);

namespace App\Shared\Adapter\In\Irc\CommandArgument;

/** Variants select on the final argument of this node and append their arguments. */
final readonly class CommandArgumentDefinition
{
    /**
     * @param list<Argument>      $arguments
     * @param array<string, self> $variants  uppercase selectors, including accepted aliases
     */
    public function __construct(
        public string $syntaxKey,
        public array $arguments = [],
        public array $variants = [],
        public bool $allowExtra = true,
    ) {}

    /** The dispatch checkpoint checks only this node, never a deeper variant. */
    public function minimumArgs(): int
    {
        $minimum = 0;
        foreach ($this->arguments as $argument) {
            if (!$argument->optional) {
                ++$minimum;
            }
        }

        return $minimum;
    }
}
