<?php

declare(strict_types=1);

namespace App\Domain\IRC\ValueObject;

readonly class Hostname
{
    public function __construct(public readonly string $value)
    {
        if ('' === $value) {
            throw new \InvalidArgumentException('Hostname cannot be empty.');
        }

        $isValidDomain = filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        $isValidIp = filter_var($value, FILTER_VALIDATE_IP) !== false;

        if (!$isValidDomain && !$isValidIp) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not a valid hostname or IP address.', $value)
            );
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
