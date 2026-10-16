<?php

declare(strict_types=1);

namespace App\CRegServ\Application\Port\Out;

use DateTimeImmutable;

/**
 * Removes a channel from the service that owns its registration.
 *
 * Internal port: the Application layer only knows this contract; the adapter is the single
 * place that knows the unregistration happens through ChanServ. Mirrors ChannelApprovalPort,
 * which is the "hand over on approval" counterpart.
 */
interface ChannelDropPort
{
    public function drop(
        string $channelName,
        string $actorNickname,
        DateTimeImmutable $occurredAt,
    ): ChannelDropOutcome;
}
