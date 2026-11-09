<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

use InvalidArgumentException;

use function ctype_alnum;
use function str_contains;
use function str_replace;
use function str_split;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

final readonly class AkickMask
{
    public const int MAX_LENGTH = 255;

    public const int MIN_ALPHANUMERIC_CHARACTERS = 4;

    private string $pattern;

    public function __construct(public string $value)
    {
        if ('' === $value || self::MAX_LENGTH < strlen($value) || !str_contains($value, '!') || !str_contains($value, '@')) {
            throw new InvalidArgumentException('AKICK mask must be a non-empty nick!ident@host pattern of at most 255 characters.');
        }

        $this->pattern = strtolower($value);
    }

    public function matches(string $userMask): bool
    {
        $subject = strtolower($userMask);
        $patternLength = strlen($this->pattern);
        $subjectLength = strlen($subject);
        $patternIndex = 0;
        $subjectIndex = 0;
        $lastStar = -1;
        $starMatchEnd = 0;

        while ($subjectIndex < $subjectLength) {
            if ($patternIndex < $patternLength && ('?' === $this->pattern[$patternIndex] || ('*' !== $this->pattern[$patternIndex] && $this->pattern[$patternIndex] === $subject[$subjectIndex]))) {
                ++$patternIndex;
                ++$subjectIndex;
            } elseif ($patternIndex < $patternLength && '*' === $this->pattern[$patternIndex]) {
                $lastStar = $patternIndex++;
                $starMatchEnd = $subjectIndex;
            } elseif (-1 !== $lastStar) {
                $patternIndex = $lastStar + 1;
                $subjectIndex = ++$starMatchEnd;
            } else {
                return false;
            }
        }

        while ($patternIndex < $patternLength && '*' === $this->pattern[$patternIndex]) {
            ++$patternIndex;
        }

        return $patternIndex === $patternLength;
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
