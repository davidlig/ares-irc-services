<?php

declare(strict_types=1);

namespace App\Domain\IRC\ValueObject;

use InvalidArgumentException;

use function sprintf;

/**
 * An IRC server name must be a fully-qualified domain name (e.g. services.example.com).
 */
readonly class ServerName
{
    public function __construct(public readonly string $value)
    {
        if ('' === $value) {
            throw new InvalidArgumentException('Server name cannot be empty.');
        }

        if (!str_contains($value, '.')) {
            throw new InvalidArgumentException(sprintf('Server name "%s" must be a FQDN containing at least one dot.', $value));
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
