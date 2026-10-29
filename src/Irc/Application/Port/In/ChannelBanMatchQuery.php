<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/** Resolves ordinary bans for the current user, without changing channel state. */
interface ChannelBanMatchQuery
{
    /** Null means evaluation unavailable, never a successful empty match set. */
    public function findMatchingBans(string $channelName, string $uid): ?ChannelBanMatchResult;
}
