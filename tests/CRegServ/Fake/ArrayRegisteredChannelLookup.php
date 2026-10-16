<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Fake;

use App\CRegServ\Application\Port\Out\RegisteredChannelLookupPort;

/**
 * Answers the "is this channel already registered?" question from a plain array,
 * so use case tests can pretend ChanServ holds a channel without a database.
 */
final class ArrayRegisteredChannelLookup implements RegisteredChannelLookupPort
{
    /** @var array<string, bool> keyed by lowercased channel name */
    public array $registered = [];

    public function existsByChannelName(string $channelName): bool
    {
        return $this->registered[strtolower($channelName)] ?? false;
    }
}
