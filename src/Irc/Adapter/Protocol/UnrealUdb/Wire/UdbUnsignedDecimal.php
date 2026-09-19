<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use Stringable;
use ValueError;

use function ltrim;
use function sprintf;
use function strcmp;
use function strlen;
use function strrev;

use const PHP_INT_MAX;

/** Canonical unsigned-long decimal used by the UDB v4 wire contract. */
final readonly class UdbUnsignedDecimal implements Stringable
{
    public const string MAX = '18446744073709551615';

    private function __construct(private string $value) {}

    public static function parse(string $value): ?self
    {
        if ('' === $value || 1 !== preg_match('/^[0-9]+\z/', $value)) {
            return null;
        }

        $canonical = ltrim($value, '0');
        $canonical = '' === $canonical ? '0' : $canonical;
        if (strlen($canonical) > strlen(self::MAX)
            || (strlen($canonical) === strlen(self::MAX) && strcmp($canonical, self::MAX) > 0)
        ) {
            return null;
        }

        return new self($canonical);
    }

    public static function fromInt(int $value): self
    {
        if (0 > $value) {
            throw new ValueError(sprintf('UDB unsigned decimals cannot be negative: %d.', $value));
        }

        return new self((string) $value);
    }

    public function isZero(): bool
    {
        return '0' === $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /** @return -1|0|1 */
    public function compare(self $other): int
    {
        $lengthComparison = strlen($this->value) <=> strlen($other->value);
        if (0 !== $lengthComparison) {
            return $lengthComparison;
        }

        return strcmp($this->value, $other->value) <=> 0;
    }

    public function toInt(): ?int
    {
        $maximum = (string) PHP_INT_MAX;
        if (strlen($this->value) > strlen($maximum)
            || (strlen($this->value) === strlen($maximum) && strcmp($this->value, $maximum) > 0)
        ) {
            return null;
        }

        return (int) $this->value;
    }

    public function increment(): ?self
    {
        if (self::MAX === $this->value) {
            return null;
        }

        $carry = 1;
        $reversed = '';
        for ($index = strlen($this->value) - 1; 0 <= $index; --$index) {
            $digit = ((int) $this->value[$index]) + $carry;
            $reversed .= (string) ($digit % 10);
            $carry = intdiv($digit, 10);
        }
        if (1 === $carry) {
            $reversed .= '1';
        }

        return new self(strrev($reversed));
    }

    /** Sender-local identifiers are non-zero and wrap after exhausting unsigned long. */
    public function incrementNonZero(): self
    {
        return $this->increment() ?? self::fromInt(1);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
