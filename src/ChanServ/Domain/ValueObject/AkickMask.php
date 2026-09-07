<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

use InvalidArgumentException;

use function fnmatch;
use function strlen;

final readonly class AkickMask
{
    public const int MAX_LENGTH = 255;

    public const int MIN_ALPHANUMERIC_CHARACTERS = 4;

    public function __construct(public string $value)
    {
        if ('' === $value || self::MAX_LENGTH < strlen($value) || !str_contains($value, '!') || !str_contains($value, '@')) {
            throw new InvalidArgumentException('AKICK mask must be a non-empty nick!ident@host pattern of at most 255 characters.');
        }
    }

    public function matches(string $userMask): bool
    {
        return fnmatch(strtolower($this->value), strtolower($userMask));
    }

    public function nicknamePattern(): string
    {
        $separator = strpos($this->value, '!');

        return false === $separator ? $this->value : substr($this->value, 0, $separator);
    }

    public function targetsSpecificNickname(): bool
    {
        return '' !== str_replace('*', '', $this->nicknamePattern());
    }

    public function isSafe(): bool
    {
        foreach (str_split($this->nicknamePattern()) as $character) {
            if (ctype_alnum($character)) {
                return true;
            }
        }

        $separator = strpos($this->value, '!');
        $identityAndHost = false === $separator ? '' : substr($this->value, $separator + 1);
        $alphanumericCharacters = 0;
        foreach (str_split($identityAndHost) as $character) {
            if (ctype_alnum($character)) {
                ++$alphanumericCharacters;
            }
        }

        return self::MIN_ALPHANUMERIC_CHARACTERS <= $alphanumericCharacters;
    }
}
