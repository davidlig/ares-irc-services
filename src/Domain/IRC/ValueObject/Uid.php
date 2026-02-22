<?php

declare(strict_types=1);

namespace App\Domain\IRC\ValueObject;

/**
 * Unique User ID in UnrealIRCd format: <SID:3chars><random:6chars> = 9 alphanumeric chars.
 * The first 3 characters identify the server (SID).
 *
 * Example: 001R2OC01
 */
readonly class Uid
{
    public function __construct(public readonly string $value)
    {
        if (!preg_match('/^[0-9A-Z]{9}$/', $value)) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not a valid UID. Expected 9 uppercase alphanumeric characters.', $value)
            );
        }
    }

    public function getSid(): string
    {
        return substr($this->value, 0, 3);
    }

    public function equals(self $other): bool
    {
        return $other->value === $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
