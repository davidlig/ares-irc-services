<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

use InvalidArgumentException;

final readonly class AccessLevel
{
    public const int UNIDENTIFIED = -1;

    public const int NONE = 0;

    public const int FOUNDER = 500;

    private function __construct(public int $value) {}

    public static function fromEffective(int $value): self
    {
        if (self::UNIDENTIFIED > $value || self::FOUNDER < $value) {
            throw new InvalidArgumentException('Effective access level must be between -1 and 500.');
        }

        return new self($value);
    }

    public static function fromStored(?int $value): self
    {
        if (null === $value) {
            return new self(self::NONE);
        }

        if (1 > $value || 499 < $value) {
            throw new InvalidArgumentException('Stored access level must be between 1 and 499.');
        }

        return new self($value);
    }

    public function isHigherThan(self $other): bool
    {
        return $this->value > $other->value;
    }

    public function meets(int $threshold): bool
    {
        return $this->value >= $threshold;
    }
}
