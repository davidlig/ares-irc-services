<?php

declare(strict_types=1);

namespace App\Shared\Adapter\In\Irc\CommandArgument;

use function count;
use function in_array;
use function strtoupper;

/** Pure structural matching. Callers retain authorization and reply ownership. */
final class CommandArgumentValidator
{
    /** @param list<string> $args */
    public static function validatePrefix(CommandArgumentDefinition $definition, array $args): ValidationResult
    {
        return new ValidationResult(true, count($args) < $definition->minimumArgs() ? $definition->syntaxKey : null);
    }

    /** @param list<string> $args */
    public static function validate(CommandArgumentDefinition $definition, array $args): ValidationResult
    {
        return self::validateNode($definition, $args, 0);
    }

    /** @param list<string> $args */
    private static function validateNode(CommandArgumentDefinition $definition, array $args, int $offset): ValidationResult
    {
        $consumesRest = false;
        foreach ($definition->arguments as $index => $argument) {
            $value = $args[$offset + $index] ?? null;
            if (null === $value) {
                if (!$argument->optional) {
                    return new ValidationResult(true, $definition->syntaxKey);
                }
                continue;
            }
            if ([] !== $argument->alternatives && !in_array(strtoupper($value), $argument->alternatives, true)) {
                return new ValidationResult(true, $definition->syntaxKey);
            }
            $consumesRest = $argument->rest;
        }

        $nextOffset = $offset + count($definition->arguments);
        if ([] !== $definition->variants) {
            $selector = strtoupper($args[$nextOffset - 1] ?? '');
            $variant = $definition->variants[$selector] ?? null;

            return null === $variant ? new ValidationResult(false) : self::validateNode($variant, $args, $nextOffset);
        }

        return new ValidationResult(true, !$definition->allowExtra && !$consumesRest && count($args) > $nextOffset ? $definition->syntaxKey : null);
    }
}
