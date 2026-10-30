<?php

declare(strict_types=1);

namespace App\Shared\Adapter\In\Irc\Presentation;

final readonly class Appearance
{
    public function __construct(
        public ?string $color = null,
        public bool $bold = false,
        public bool $underline = false,
        public string $prefix = '',
        public string $suffix = '',
    ) {}

    public function apply(string $text): string
    {
        if ('' === $text) {
            return '';
        }
        $open = ($this->bold ? "\x02" : '') . ($this->underline ? "\x1F" : '')
            . (null !== $this->color ? "\x03" . $this->color : '');
        $close = '' !== $open ? "\x03\x0F" : '';

        return $this->prefix . $open . $text . $close . $this->suffix;
    }
}
