<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\Out\ChanServ;

use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\CRegServ\Application\Port\Out\RegisteredChannelLookupPort;

/**
 * Answers CReG's channel-registration questions from ChanServ's public projection.
 *
 * CReG must not own channel registrations: APROBAR hands the request over to ChanServ's
 * RegisterChannel, which is what writes the UDB C block. ChanServ's outbound repository is
 * internal, so this adapter reads through the inbound ChannelProjectionQuery port instead.
 */
final readonly class ChanServRegisteredChannelLookupAdapter implements RegisteredChannelLookupPort
{
    public function __construct(private ChannelProjectionQuery $channelProjections) {}

    public function existsByChannelName(string $channelName): bool
    {
        return null !== $this->channelProjections->findByName($channelName);
    }
}
