<?php

declare(strict_types=1);

namespace App\Domain\IRC\ValueObject;

use InvalidArgumentException;

use function sprintf;
use function strlen;

/**
 * IRC channel name. Must start with # and contain no spaces or control chars.
 */
readonly class ChannelName
{
    public function __construct(public readonly string $value)
    {
        if (!str_starts_with($value, '#')) {
            throw new InvalidArgumentException(sprintf('Channel name "%s" must start with #.', $value));
        }

        if (strlen($value) < 2) {
            throw new InvalidArgumentException('Channel name cannot be just "#".');
        }

        if (preg_match('/[\s\x00\x07,:]/', $value)) {
            throw new InvalidArgumentException(sprintf('Channel name "%s" contains invalid characters.', $value));
        }
    }

    public function equals(self $other): bool
    {
        return 0 === strcasecmp($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
