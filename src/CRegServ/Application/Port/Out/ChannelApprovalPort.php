<?php

declare(strict_types=1);

namespace App\CRegServ\Application\Port\Out;

use DateTimeImmutable;

/**
 * Turns an approved request into a real channel registration.
 *
 * Internal port: the Application layer only knows this contract; the adapter is the
 * single place that knows the registration happens through ChanServ.
 */
interface ChannelApprovalPort
{
    public function approve(
        string $channelName,
        string $description,
        int $requesterAccountId,
        string $approverNickname,
        DateTimeImmutable $occurredAt,
    ): ChannelApprovalOutcome;
}
