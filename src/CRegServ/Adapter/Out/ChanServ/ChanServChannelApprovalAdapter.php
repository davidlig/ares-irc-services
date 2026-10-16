<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\Out\ChanServ;

use App\ChanServ\Application\Port\In\ChannelRegistration;
use App\ChanServ\Application\Port\In\ChannelRegistrationOutcome;
use App\CRegServ\Application\Port\Out\ChannelApprovalOutcome;
use App\CRegServ\Application\Port\Out\ChannelApprovalPort;
use DateTimeImmutable;

/**
 * Approves a CReG request by handing the channel over to ChanServ, which writes the
 * UDB C block. This adapter is the only CReG code that knows ChanServ exists.
 */
final readonly class ChanServChannelApprovalAdapter implements ChannelApprovalPort
{
    public function __construct(
        private ChannelRegistration $channelRegistration,
    ) {}

    public function approve(
        string $channelName,
        string $description,
        int $requesterAccountId,
        string $approverNickname,
        DateTimeImmutable $occurredAt,
    ): ChannelApprovalOutcome {
        return match ($this->channelRegistration->registerApproved(
            $channelName,
            $description,
            $requesterAccountId,
            $approverNickname,
            $occurredAt,
        )) {
            ChannelRegistrationOutcome::Registered => ChannelApprovalOutcome::Approved,
            ChannelRegistrationOutcome::PendingDeletion => ChannelApprovalOutcome::AlreadyRegistered,
            ChannelRegistrationOutcome::AlreadyRegistered => ChannelApprovalOutcome::AlreadyRegistered,
            ChannelRegistrationOutcome::Failed => ChannelApprovalOutcome::Failed,
        };
    }
}
