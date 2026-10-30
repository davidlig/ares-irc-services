<?php

declare(strict_types=1);

namespace App\Shared\Adapter\In\Irc\Presentation;

final readonly class FixedStyleProvider implements StyleProvider
{
    public function __construct(private PresentationStyle $style) {}

    public function current(): PresentationStyle
    {
        return $this->style;
    }
}
