<?php

declare(strict_types=1);

namespace App\Shared\Adapter\In\Irc\Presentation;

use function explode;
use function implode;
use function preg_split;
use function str_repeat;

use const PREG_SPLIT_DELIM_CAPTURE;

/** Templates are already localized; interpolation is simultaneous, never recursive. */
final readonly class IrcRenderer
{
    public function __construct(private StyleProvider $styles) {}

    /** @param array<string, string|Fragment> $parameters */
    public function reply(Role $role, string $template, array $parameters = []): string
    {
        $text = $this->component(Component::Text, $template, $parameters);
        $marker = $this->styles->current()->marker($role);
        if ('' === $marker) {
            return $text;
        }
        $lines = explode("\n", $text);
        foreach ($lines as &$line) {
            if ('' !== $line && ' ' !== $line) {
                $line = $marker . ' ' . $line;
                break;
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    /** @param array<string, string|Fragment> $parameters */
    public function component(Component $component, string $template, array $parameters = []): string
    {
        $appearance = $this->styles->current()->appearance($component);
        $bodyStyle = new Appearance($appearance->color, $appearance->bold, $appearance->underline);
        $pieces = preg_split('/(%[^%\\r\\n]+%)/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $pending = '';
        $output = '';
        foreach (false === $pieces ? [$template] : $pieces as $piece) {
            $value = $parameters[$piece] ?? $piece;
            if ($value instanceof Fragment) {
                $output .= $this->applyLines($bodyStyle, $pending);
                $pending = '';
                $output .= $this->component($value->component, $value->text, $value->parameters);
            } else {
                $pending .= $value;
            }
        }
        $output .= $this->applyLines($bodyStyle, $pending);
        $lines = explode("\n", $output);
        foreach ($lines as &$line) {
            if ('' !== $line) {
                $line = $appearance->prefix . $line . $appearance->suffix;
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    public function separator(int $width = 40): string
    {
        return $this->component(Component::Separator, str_repeat($this->styles->current()->separator(), $width));
    }

    private function applyLines(Appearance $appearance, string $text): string
    {
        $lines = explode("\n", $text);
        foreach ($lines as &$line) {
            $line = $appearance->apply($line);
        }
        unset($line);

        return implode("\n", $lines);
    }
}
