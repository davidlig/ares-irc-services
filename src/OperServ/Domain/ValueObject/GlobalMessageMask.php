<?php

declare(strict_types=1);

namespace App\OperServ\Domain\ValueObject;

use ValueError;

use function mb_strlen;
use function preg_match;
use function sprintf;

/** Identity used for the short-lived sender of a GLOBAL message. */
final readonly class GlobalMessageMask
{
    private function __construct(
        public string $nickname,
        public string $ident,
        public string $vhost,
    ) {}

    public static function fromString(string $mask): self
    {
        if (1 !== preg_match('/^([^!]+)!([^@]+)@(.+)$/', $mask, $matches)) {
            throw new ValueError('Invalid mask format. Expected: nick!ident@vhost');
        }

        self::validateNickname($matches[1]);
        self::validateIdent($matches[2]);
        self::validateVhost($matches[3]);

        return new self($matches[1], $matches[2], $matches[3]);
    }

    private static function validateNickname(string $nickname): void
    {
        if (30 < mb_strlen($nickname)) {
            throw new ValueError('Nickname cannot exceed 30 characters');
        }

        if (1 !== preg_match('/^[a-zA-Z\[\]{}|\\\\`^-][a-zA-Z0-9\[\]{}|\\\\`^-]*$/', $nickname)) {
            throw new ValueError('Nickname must start with a letter or []{}|\\`^- and contain only alphanumeric characters and []{}|\\`^-');
        }
    }

    private static function validateIdent(string $ident): void
    {
        if (20 < mb_strlen($ident)) {
            throw new ValueError('Ident cannot exceed 20 characters');
        }

        if (1 !== preg_match('/^[a-zA-Z0-9._~-]+$/', $ident)) {
            throw new ValueError('Ident can only contain alphanumeric characters and . _ ~ -');
        }
    }

    private static function validateVhost(string $vhost): void
    {
        if (63 < mb_strlen($vhost)) {
            throw new ValueError('Vhost cannot exceed 63 characters');
        }

        if (1 !== preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*[a-zA-Z0-9]$/', $vhost)) {
            throw new ValueError('Vhost must be a valid hostname');
        }
    }

    public function __toString(): string
    {
        return sprintf('%s!%s@%s', $this->nickname, $this->ident, $this->vhost);
    }
}
