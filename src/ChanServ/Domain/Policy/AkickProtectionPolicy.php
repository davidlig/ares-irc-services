<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Policy;

use App\ChanServ\Domain\ValueObject\AkickMask;
use Closure;

final readonly class AkickProtectionPolicy
{
    /**
     * @param list<string>            $protectedNicknames
     * @param Closure(string): string $fold               negotiated identity fold
     */
    public function firstProtectedNickname(AkickMask $mask, array $protectedNicknames, \Closure $fold): ?string
    {
        if (!$mask->targetsSpecificNickname()) {
            return null;
        }

        foreach ($protectedNicknames as $nickname) {
            if ($mask->nicknameMatches($nickname, $fold)) {
                return $nickname;
            }
        }

        return null;
    }
}
