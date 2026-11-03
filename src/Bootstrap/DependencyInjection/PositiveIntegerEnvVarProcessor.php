<?php

declare(strict_types=1);

namespace App\Bootstrap\DependencyInjection;

use Closure;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

use function filter_var;
use function is_int;
use function is_string;
use function sprintf;

/** Reject fractional or overflowing configuration instead of silently coercing it. */
final class PositiveIntegerEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, Closure $getEnv): int
    {
        $raw = $getEnv($name);
        if (!is_int($raw) && !is_string($raw)) {
            throw new RuntimeException(sprintf('%s must be a positive integer.', $name));
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $value) {
            throw new RuntimeException(sprintf('%s must be a positive integer.', $name));
        }

        return $value;
    }

    public static function getProvidedTypes(): array
    {
        return ['positive_int' => 'int'];
    }
}
