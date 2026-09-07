<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Policy;

use App\ChanServ\Domain\ValueObject\AkickMask;

use function fnmatch;

final readonly class AkickProtectionPolicy
{
    /** @param list<string> $protectedNicknames */
    public function firstProtectedNickname(AkickMask $mask, array $protectedNicknames): ?string
    {
        if (!$mask->targetsSpecificNickname()) {
            return null;
        }

        $pattern = strtolower($mask->nicknamePattern());
        foreach ($protectedNicknames as $nickname) {
            if (fnmatch($pattern, strtolower($nickname))) {
                return $nickname;
            }
        }

        return null;
    }
}
