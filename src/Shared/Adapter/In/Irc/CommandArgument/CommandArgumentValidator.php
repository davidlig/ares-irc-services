<?php

declare(strict_types=1);

namespace App\Shared\Adapter\In\Irc\CommandArgument;

use function array_slice;
use function count;
use function ctype_digit;
use function implode;
use function in_array;
use function str_starts_with;
use function strtoupper;
use function trim;

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
        $result = self::validateOrderedNode($definition, $args, $offset);
        if ($result->recognized) {
            return $result;
        }
        foreach ($definition->alternativeOrders as $order) {
            $tail = array_slice($args, $offset);
            $reordered = array_slice($args, 0, $offset);
            foreach ($order as $index) {
                if (isset($tail[$index])) {
                    $reordered[] = $tail[$index];
                }
            }
            foreach ($tail as $index => $value) {
                if (!in_array($index, $order, true)) {
                    $reordered[] = $value;
                }
            }
            $alternative = self::validateOrderedNode($definition, $reordered, $offset);
            if ($alternative->recognized) {
                return $alternative;
            }
        }

        return $result;
    }

    /** @param list<string> $args */
    private static function validateOrderedNode(CommandArgumentDefinition $definition, array $args, int $offset): ValidationResult
    {
        $cursor = $offset;
        $consumesRest = false;
        $selector = '';
        foreach ($definition->arguments as $argument) {
            $value = isset($args[$cursor]) && $argument->rest ? implode(' ', array_slice($args, $cursor)) : ($args[$cursor] ?? null);
            $selector = '';
            if (null === $value || '' === $value || (null !== $argument->prefix && !str_starts_with($value, $argument->prefix))) {
                if (!$argument->optional) {
                    return new ValidationResult(true, $definition->syntaxKey);
                }
                continue;
            }
            if ($argument->nonBlank && '' === trim($value)) {
                return new ValidationResult(true, $definition->syntaxKey);
            }
            if (([] !== $argument->alternatives || $argument->digits)
                && !in_array(strtoupper($value), $argument->alternatives, true)
                && (!$argument->digits || !ctype_digit($value))) {
                return new ValidationResult(true, $definition->syntaxKey);
            }
            $selector = strtoupper($value);
            $consumesRest = $argument->rest;
            $cursor = $argument->rest ? count($args) : $cursor + 1;
        }

        if ([] !== $definition->variants) {
            $variant = $definition->variants[$selector] ?? null;
            if (null !== $variant) {
                return self::validateNode($variant, $args, $cursor);
            }
            $fallback = null === $definition->unknownVariant ? null : self::validateNode($definition->unknownVariant, $args, $cursor)->syntaxKey;

            return new ValidationResult(false, $fallback);
        }

        return new ValidationResult(true, !$definition->allowExtra && !$consumesRest && count($args) > $cursor ? $definition->syntaxKey : null);
    }
}
