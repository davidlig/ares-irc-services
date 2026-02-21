<?php

declare(strict_types=1);

namespace App\Domain\IRC\ValueObject;

/**
 * An IRC nickname.
 * Valid characters: A-Z a-z 0-9 - _ [ ] { } | \ `
 * Cannot start with a digit or hyphen.
 */
readonly class Nick
{
    public function __construct(public readonly string $value)
    {
        if ('' === $value) {
            throw new \InvalidArgumentException('Nick cannot be empty.');
        }

        if (!preg_match('/^[A-Za-z\[\]\\\\`_^{|}][A-Za-z0-9\[\]\\\\`_^{|}\-]*$/', $value)) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not a valid IRC nickname.', $value)
            );
        }
    }

    public function equals(self $other): bool
    {
        return strcasecmp($this->value, $other->value) === 0;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
