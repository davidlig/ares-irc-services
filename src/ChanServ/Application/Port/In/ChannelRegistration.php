<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

use DateTimeImmutable;

/**
 * Registers a channel on behalf of a requester.
 *
 * Public entry point for other services (currently CReG, whose APROBAR approves a
 * pending request) to create a real registration and write the UDB C block, without
 * depending on the RegisterChannel use case internals.
 *
 * The registration is forced: the requester need not be in the channel and the
 * per-nick throttle and founder limit do not apply.
 */
interface ChannelRegistration
{
    /**
     * @param int $requesterAccountId Account that becomes the channel founder
     */
    public function registerApproved(
        string $channelName,
        string $description,
        int $requesterAccountId,
        string $approverNickname,
        DateTimeImmutable $occurredAt,
    ): ChannelRegistrationOutcome;
}
