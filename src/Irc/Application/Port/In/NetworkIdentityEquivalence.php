<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/**
 * Nickname and channel-name equivalence under the casemapping the IRCd negotiated for the
 * current link (ASCII until a mapping is negotiated, and again after the link resets).
 *
 * Services keep their stored lookup keys on the fixed RFC 1459 fold; they use this port to
 * confirm that a network name and a stored name denote the same identity on this network.
 */
interface NetworkIdentityEquivalence
{
    /** Folds a nickname, channel name or nick!ident@host mask with the negotiated casemapping. */
    public function fold(string $value): string;

    public function equivalent(string $left, string $right): bool;
}
