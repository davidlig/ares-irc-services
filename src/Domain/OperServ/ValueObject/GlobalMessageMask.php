<?php

declare(strict_types=1);

namespace App\Domain\OperServ\ValueObject;

use ValueError;

use function preg_match;
use function sprintf;

final readonly class GlobalMessageMask
{
    public string $nickname;

    public string $ident;

    public string $vhost;

    private function __construct(string $nickname, string $ident, string $vhost)
    {
        $this->nickname = $nickname;
        $this->ident = $ident;
        $this->vhost = $vhost;
    }

    public static function fromString(string $mask): self
    {
        $pattern = '/^([^!]+)!([^@]+)@(.+)$/';

        if (1 !== preg_match($pattern, $mask, $matches)) {
            throw new ValueError('Invalid mask format. Expected: nick!ident@vhost');
        }

        $nickname = $matches[1];
        $ident = $matches[2];
        $vhost = $matches[3];

        self::validateNickname($nickname);
        self::validateIdent($ident);
        self::validateVhost($vhost);

        return new self($nickname, $ident, $vhost);
    }

    private static function validateNickname(string $nickname): void
    {
        if (30 < mb_strlen($nickname)) {
            throw new ValueError('Nickname cannot exceed 30 characters');
        }

        $pattern = '/^[a-zA-Z\[\]{}|\\\\`^-][a-zA-Z0-9\[\]{}|\\\\`^-]*$/';

        if (1 !== preg_match($pattern, $nickname)) {
            throw new ValueError('Nickname must start with a letter or []{}|\\`^- and contain only alphanumeric characters and []{}|\\`^-');
        }
    }

    private static function validateIdent(string $ident): void
    {
        if (20 < mb_strlen($ident)) {
            throw new ValueError('Ident cannot exceed 20 characters');
        }

        $pattern = '/^[a-zA-Z0-9._~-]+$/';

        if (1 !== preg_match($pattern, $ident)) {
            throw new ValueError('Ident can only contain alphanumeric characters and . _ ~ -');
        }
    }

    private static function validateVhost(string $vhost): void
    {
        if (63 < mb_strlen($vhost)) {
            throw new ValueError('Vhost cannot exceed 63 characters');
        }

        $pattern = '/^[a-zA-Z0-9][a-zA-Z0-9.-]*[a-zA-Z0-9]$/';

        if (1 !== preg_match($pattern, $vhost)) {
            throw new ValueError('Vhost must be a valid hostname');
        }
    }

    public function __toString(): string
    {
        return sprintf('%s!%s@%s', $this->nickname, $this->ident, $this->vhost);
    }
}
