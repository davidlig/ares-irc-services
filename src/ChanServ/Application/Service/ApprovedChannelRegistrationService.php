<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Service;

use App\ChanServ\Application\Port\In\ChannelRegistration;
use App\ChanServ\Application\Port\In\ChannelRegistrationOutcome;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannel;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandlerInterface;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelOutcome;
use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;
use DateTimeImmutable;

/**
 * Registers a channel on behalf of a requester, as done when a services oper approves
 * a pending CReG request.
 *
 * The registration runs forced and privileged: the oper is neither the founder nor
 * necessarily in the channel, so the channel-presence, rank, throttle and per-nick
 * limits that guard the self-service REGISTER do not apply. The founder stays the
 * requester. Pulsar needs the channel to hold users before it can attach the
 * ChannelInfo (do_aprobar's "canal vacío" check); ares does not, because the UDB C
 * block is produced by the sync subscriber without a live channel object.
 */
final readonly class ApprovedChannelRegistrationService implements ChannelRegistration
{
    public function __construct(
        private RegisterChannelHandlerInterface $registerChannel,
    ) {}

    public function registerApproved(
        string $channelName,
        string $description,
        int $requesterAccountId,
        string $approverNickname,
        DateTimeImmutable $occurredAt,
    ): ChannelRegistrationOutcome {
        try {
            $result = $this->registerChannel->handle(new RegisterChannel(
                channelName: $channelName,
                description: $description,
                accountId: $requesterAccountId,
                actorNickname: $approverNickname,
                actorIdentified: true,
                actorIrcOperator: true,
                channelExistsOnNetwork: false,
                hasRequiredChannelRank: false,
                occurredAt: $occurredAt,
                force: true,
            ));
        } catch (ChannelAlreadyRegisteredException) {
            return ChannelRegistrationOutcome::AlreadyRegistered;
        }

        return match ($result->outcome) {
            RegisterChannelOutcome::Registered => ChannelRegistrationOutcome::Registered,
            RegisterChannelOutcome::PendingDeletion => ChannelRegistrationOutcome::PendingDeletion,
            default => ChannelRegistrationOutcome::Failed,
        };
    }
}
