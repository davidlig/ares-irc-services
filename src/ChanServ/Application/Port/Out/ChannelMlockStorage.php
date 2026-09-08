<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;

/**
 * Persists a semantic MLOCK snapshot in the channel's legacy storage fields.
 */
interface ChannelMlockStorage
{
    public function store(RegisteredChannel $channel, ChannelModeLock $modeLock): void;
}
