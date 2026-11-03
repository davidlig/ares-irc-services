<?php

declare(strict_types=1);

namespace App\Bootstrap\DependencyInjection;

use Closure;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

use function in_array;
use function is_string;
use function sprintf;

/** Only absence disables composition; explicit invalid values fail closed. */
final class OptionalPasswordComplexityEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, Closure $getEnv): string
    {
        try {
            $value = $getEnv($name);
        } catch (EnvNotFoundException) {
            return 'none';
        }
        if (!is_string($value) || !in_array($value, ['low', 'mid', 'high'], true)) {
            throw new RuntimeException(sprintf('%s must be low, mid or high when defined; leave it unset to disable complexity.', $name));
        }

        return $value;
    }

    public static function getProvidedTypes(): array
    {
        return ['optional_password_complexity' => 'string'];
    }
}
