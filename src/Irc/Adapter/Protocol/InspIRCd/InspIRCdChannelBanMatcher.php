<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\InspIRCd;

use App\Irc\Adapter\Protocol\ChannelBanIdentity;
use App\Irc\Adapter\Protocol\ChannelBanMatcher;
use App\Irc\Adapter\Protocol\IrcMaskPattern;

use function ctype_digit;
use function explode;
use function inet_pton;
use function min;
use function preg_match;
use function str_contains;
use function strlen;

/** Ordinary bans: InspIRCd v4 Channel::CheckBan and MatchCIDR. */
final readonly class InspIRCdChannelBanMatcher implements ChannelBanMatcher
{
    public function __construct(private string $caseMapping = 'ascii') {}

    public function matches(string $mask, ChannelBanIdentity $identity): bool
    {
        // Extban prefixes may wrap an otherwise ordinary-looking mask.
        if (1 === preg_match('/^!?[^!@:]+:/', $mask) || !str_contains($mask, '@')) {
            return false;
        }
        [$user, $host] = explode('@', $mask, 2);
        if (!IrcMaskPattern::matches($user, $identity->nick . '!' . $identity->ident, $this->caseMapping) && !IrcMaskPattern::matches($user, $identity->nick . '!' . $identity->realIdent, $this->caseMapping)) {
            return false;
        }
        if (IrcMaskPattern::matches($host, $identity->realHost, $this->caseMapping) || IrcMaskPattern::matches($host, $identity->displayHost, $this->caseMapping)) {
            return true;
        }
        if (null === $identity->ip) {
            return false;
        }
        $parts = explode('/', $host, 2);
        $binary = inet_pton($parts[0]);
        if (false !== $binary) {
            $width = strlen($binary) * 8;
            $prefix = $parts[1] ?? (string) $width;
            if (ctype_digit($prefix) && IrcMaskPattern::addressMatches($parts[0], $identity->ip, min((int) $prefix, $width))) {
                return true;
            }
        }

        return IrcMaskPattern::matches($host, $identity->ip, $this->caseMapping);
    }
}
