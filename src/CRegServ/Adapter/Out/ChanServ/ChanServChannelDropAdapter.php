<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\Out\ChanServ;

use App\ChanServ\Application\Port\In\ChannelDeregistrationOutcome;
use App\ChanServ\Application\Port\In\ChannelRegistration;
use App\CRegServ\Application\Port\Out\ChannelDropOutcome;
use App\CRegServ\Application\Port\Out\ChannelDropPort;
use DateTimeImmutable;

/**
 * Unregisters a CReG channel by handing the drop over to ChanServ, which owns the
 * registration. This adapter is the only CReG code that knows ChanServ exists.
 */
final readonly class ChanServChannelDropAdapter implements ChannelDropPort
{
    public function __construct(
        private ChannelRegistration $channelRegistration,
    ) {}

    public function drop(
        string $channelName,
        string $actorNickname,
        DateTimeImmutable $occurredAt,
    ): ChannelDropOutcome {
        return match ($this->channelRegistration->dropRegistered(
            $channelName,
            $actorNickname,
            $occurredAt,
        )) {
            ChannelDeregistrationOutcome::Dropped => ChannelDropOutcome::Dropped,
            ChannelDeregistrationOutcome::NotRegistered => ChannelDropOutcome::NotRegistered,
            ChannelDeregistrationOutcome::Failed => ChannelDropOutcome::Failed,
        };
    }
}
