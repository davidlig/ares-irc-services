<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol;

interface ChannelBanMatcher
{
    public function matches(string $mask, ChannelBanIdentity $identity): bool;
}
