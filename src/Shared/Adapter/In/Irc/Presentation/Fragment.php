<?php

declare(strict_types=1);

namespace App\Shared\Adapter\In\Irc\Presentation;

final readonly class Fragment
{
    /** @param array<string, string|self> $parameters Explicit nested template parameters, never inherited from surrounding text. */
    public function __construct(
        public string $text,
        public Component $component = Component::Text,
        public array $parameters = [],
    ) {}
}
