<?php

declare(strict_types=1);

namespace App\Domain\IRC\ValueObject;

use InvalidArgumentException;

use function sprintf;
use function strlen;

/**
 * Opaque unique identifier for a user session on the IRC network.
 *
 * The domain does not assume any format or length; it is defined by the
 * protocol (UnrealIRCd, InspIRCd, etc.). Adapters in Infrastructure map
 * the IRCd's native user ID to this value object. This keeps the domain
 * decoupled from any specific IRCd.
 */
readonly class Uid
{
    private const MAX_LENGTH = 128;

    public function __construct(public readonly string $value)
    {
        if ('' === $value) {
            throw new InvalidArgumentException('UID cannot be empty.');
        }

        if (strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('UID must not exceed %d characters.', self::MAX_LENGTH));
        }

        if (1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('UID must not contain control characters.');
        }
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
