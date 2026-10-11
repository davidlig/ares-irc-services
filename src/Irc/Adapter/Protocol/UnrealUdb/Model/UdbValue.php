<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Model;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;

final readonly class UdbValue
{
    private function __construct(
        public UdbValueKind $kind,
        public string $value,
    ) {}

    public static function none(): self
    {
        return new self(UdbValueKind::None, '');
    }

    public static function string(string $value): self
    {
        return new self(UdbValueKind::String, $value);
    }

    public static function number(UdbUnsignedDecimal $value): self
    {
        return new self(UdbValueKind::Number, (string) $value);
    }

    public function logicalValue(): string
    {
        return match ($this->kind) {
            UdbValueKind::None => '',
            UdbValueKind::String => $this->value,
            UdbValueKind::Number => '*' . $this->value,
        };
    }
}
