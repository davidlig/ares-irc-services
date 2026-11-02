<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Help;

use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;

use function is_array;
use function is_string;

/** Resolves command-owned presentation metadata without knowing commands or HELP keys. */
final class HelpParameterResolver
{
    /**
     * @param array<string, mixed>     $parameters
     * @param callable(string): string $translate
     *
     * @return array<string, mixed>
     */
    public function resolve(array $parameters, callable $translate, bool $styled): array
    {
        foreach ($parameters as $name => $parameter) {
            if (!is_array($parameter) || !($parameter['component'] ?? null) instanceof Component || !is_string($parameter['value'] ?? null)) {
                continue;
            }

            $text = true === ($parameter['translate'] ?? false) ? $translate($parameter['value']) : $parameter['value'];
            $parameters[$name] = $styled ? new Fragment($text, $parameter['component']) : $text;
        }

        return $parameters;
    }
}
