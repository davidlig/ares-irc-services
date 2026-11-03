<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

use InvalidArgumentException;

use function in_array;
use function mb_strlen;
use function strpbrk;

/** Policy for new credentials only, never for authenticating existing hashes. */
final readonly class PasswordPolicy
{
    public const string LOWERCASE = 'abcdefghijklmnopqrstuvwxyz';

    public const string UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public const string DIGITS = '0123456789';

    public const string SYMBOLS = '!@#$%&*+-_=.?';

    public function __construct(public int $minimumLength = 8, public string $complexity = 'none')
    {
        if (1 > $minimumLength) {
            throw new InvalidArgumentException('NICKSERV_PASSWORD_MIN_LENGTH must be a positive integer.');
        }
        if (!in_array($complexity, ['none', 'low', 'mid', 'high'], true)) {
            throw new InvalidArgumentException('NICKSERV_PASSWORD_COMPLEXITY must be one of: none, low, mid, high.');
        }
    }

    public function isSatisfiedBy(string $password): bool
    {
        if (mb_strlen($password, 'UTF-8') < $this->minimumLength) {
            return false;
        }
        foreach ($this->requiredCharacterSets() as $characters) {
            if (false === strpbrk($password, $characters)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<non-empty-string> */
    public function requiredCharacterSets(): array
    {
        return match ($this->complexity) {
            'none' => [],
            'low' => [self::LOWERCASE . self::UPPERCASE, self::DIGITS],
            'mid' => [self::LOWERCASE, self::UPPERCASE, self::DIGITS],
            default => [self::LOWERCASE, self::UPPERCASE, self::DIGITS, self::SYMBOLS],
        };
    }
}
