<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\ChannelBanIdentity;
use App\Irc\Adapter\Protocol\ChannelBanMatcher;
use App\Irc\Adapter\Protocol\IrcMaskPattern;

use function ctype_digit;
use function explode;
use function inet_pton;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpbrk;

/** Ordinary bans: UnrealIRCd 6.2.7 _match_user(MATCH_CHECK_ALL). Extbans excluded. */
final readonly class UnrealUdbChannelBanMatcher implements ChannelBanMatcher
{
    public function matches(string $mask, ChannelBanIdentity $identity): bool
    {
        if (str_starts_with($mask, '~') && str_contains($mask, ':')) {
            return false;
        }
        $host = $mask;
        if (str_contains($host, '!')) {
            [$nick, $host] = explode('!', $host, 2);
            if (!IrcMaskPattern::matches($nick, $identity->nick)) {
                return false;
            }
        }
        if (str_contains($host, '@')) {
            [$ident, $host] = explode('@', $host, 2);
            if (!IrcMaskPattern::matches($ident, $identity->ident)) {
                return false;
            }
        }
        if (IrcMaskPattern::matches($host, $identity->displayHost) || IrcMaskPattern::matches($host, $identity->cloakedHost)) {
            return true;
        }
        // Unreal checks displayed/cloaked hosts before stripping a CIDR suffix.
        // The remaining mask is then reused for wildcard IP and real-host checks.
        $parts = explode('/', $host, 2);
        $host = $parts[0];
        $prefix = $parts[1] ?? null;
        if (null !== $prefix && (!ctype_digit($prefix) || (int) $prefix <= 0)) {
            return false;
        }
        if (null !== $identity->ip) {
            if (false !== strpbrk($host, '*?')) {
                if (IrcMaskPattern::matches($host, $identity->ip)) {
                    return true;
                }
            } else {
                $binary = inet_pton($host);
                if (false !== $binary) {
                    $width = strlen($binary) * 8;
                    $bits = null === $prefix ? $width : (int) $prefix;
                    if ($bits <= $width && IrcMaskPattern::addressMatches($host, $identity->ip, $bits)) {
                        return true;
                    }
                }
            }
        }

        return IrcMaskPattern::matches($host, $identity->realHost);
    }
}
