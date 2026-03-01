<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Provides the channel mode support for the currently active IRCd connection.
 * Used by ChanServ to show/hide mode-dependent commands (ADMIN, HALFOP, etc.).
 */
interface ActiveChannelModeSupportProviderInterface
{
    public function getSupport(): ChannelModeSupportInterface;
}
