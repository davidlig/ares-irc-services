<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol;

/** Optional runtime capability; public protocol contracts remain unchanged. */
interface ChannelBanMatcherProvider
{
    public function getChannelBanMatcher(): ?ChannelBanMatcher;
}
