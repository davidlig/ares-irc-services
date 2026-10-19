<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Policy;

use function preg_match;
use function preg_quote;
use function sprintf;
use function str_replace;

/**
 * Decides whether an IRC ban mask (+b) matches a user identity.
 *
 * The mask is compared against the full nick!ident@host string, case-insensitively,
 * using IRC wildcards: `*` matches any sequence and `?` matches one character.
 */
final readonly class BanMaskMatcher
{
    public function matches(string $mask, string $nick, string $ident, string $host): bool
    {
        $quoted = preg_quote($mask, '/');
        $pattern = str_replace(['\\*', '\\?'], ['.*', '.'], $quoted);
        $identity = sprintf('%s!%s@%s', $nick, $ident, $host);

        return 1 === preg_match('/\A' . $pattern . '\z/i', $identity);
    }
}
