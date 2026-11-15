<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

use Closure;
use InvalidArgumentException;

use function ctype_alnum;
use function str_contains;
use function str_replace;
use function str_split;
use function strlen;
use function strpos;
use function substr;

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

    /**
     * Matches nick!ident@host with the casemapping the IRCd negotiated, so the decision agrees with
     * the ban the IRCd applies. Only `*` and `?` are wildcards; every other byte is literal.
     *
     * @param Closure(string): string $fold negotiated identity fold
     */
    public function matches(string $userMask, Closure $fold): bool
    {
        return self::glob($fold($this->value), $fold($userMask));
    }

    /** @param Closure(string): string $fold negotiated identity fold */
    public function nicknameMatches(string $nickname, Closure $fold): bool
    {
        return self::glob($fold($this->nicknamePattern()), $fold($nickname));
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

    private static function glob(string $pattern, string $subject): bool
    {
        $patternLength = strlen($pattern);
        $subjectLength = strlen($subject);
        $patternIndex = 0;
        $subjectIndex = 0;
        $lastStar = -1;
        $starMatchEnd = 0;

        while ($subjectIndex < $subjectLength) {
            if ($patternIndex < $patternLength && ('?' === $pattern[$patternIndex] || ('*' !== $pattern[$patternIndex] && $pattern[$patternIndex] === $subject[$subjectIndex]))) {
                ++$patternIndex;
                ++$subjectIndex;
            } elseif ($patternIndex < $patternLength && '*' === $pattern[$patternIndex]) {
                $lastStar = $patternIndex++;
                $starMatchEnd = $subjectIndex;
            } elseif (-1 !== $lastStar) {
                $patternIndex = $lastStar + 1;
                $subjectIndex = ++$starMatchEnd;
            } else {
                return false;
            }
        }

        while ($patternIndex < $patternLength && '*' === $pattern[$patternIndex]) {
            ++$patternIndex;
        }

        return $patternIndex === $patternLength;
    }
}
