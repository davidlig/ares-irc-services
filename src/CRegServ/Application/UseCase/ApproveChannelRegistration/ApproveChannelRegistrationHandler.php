<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\ApproveChannelRegistration;

use App\CRegServ\Application\Port\Out\ChannelApprovalOutcome;
use App\CRegServ\Application\Port\Out\ChannelApprovalPort;
use App\CRegServ\Application\Port\Out\ChannelRegistrationRequestRepositoryInterface;
use App\CRegServ\Application\Port\Out\RegisteredChannelLookupPort;
use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;
use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;

use function str_starts_with;

/**
 * Approves a pending channel registration request.
 *
 * The pending record itself never reaches UDB: the C block is written here, on approval,
 * by handing the channel to ChanServ. The checks mirror pulsar's do_aprobar
 * (modules/cregserv/main.c:577-656), which refuses a channel ChanServ already holds, a
 * channel with no request in flight, and a request already approved or denied.
 */
final readonly class ApproveChannelRegistrationHandler implements ApproveChannelRegistrationHandlerInterface
{
    public function __construct(
        private ChannelRegistrationRequestRepositoryInterface $requestRepository,
        private RegisteredChannelLookupPort $channelLookup,
        private ChannelApprovalPort $approvalPort,
    ) {}

    public function handle(ApproveChannelRegistration $command): ApproveChannelRegistrationResult
    {
        if (!str_starts_with($command->channelName, '#')) {
            return ApproveChannelRegistrationResult::invalidChannel($command->channelName);
        }

        if ($this->channelLookup->existsByChannelName($command->channelName)) {
            return ApproveChannelRegistrationResult::alreadyRegisteredElsewhere($command->channelName);
        }

        $request = $this->requestRepository->findByChannel($command->channelName);
        if (null === $request) {
            return ApproveChannelRegistrationResult::noPendingRequest($command->channelName);
        }

        if (ChannelRegistrationStatus::Approved === $request->getStatus()) {
            return ApproveChannelRegistrationResult::alreadyApproved($request->getChannel(), $request->getRequesterNick());
        }

        if (ChannelRegistrationStatus::Denied === $request->getStatus()) {
            return ApproveChannelRegistrationResult::alreadyDenied($request->getChannel());
        }

        $outcome = $this->approvalPort->approve(
            channelName: $request->getChannel(),
            description: $request->getDescription(),
            requesterAccountId: $request->getRequesterAccountId(),
            approverNickname: $command->approverNickname,
            occurredAt: $command->occurredAt,
        );

        return match ($outcome) {
            ChannelApprovalOutcome::Approved => $this->markApproved($request, $command),
            // The name got taken between the lookup above and the registration itself.
            ChannelApprovalOutcome::AlreadyRegistered,
            ChannelApprovalOutcome::PendingDeletion => ApproveChannelRegistrationResult::alreadyRegisteredElsewhere(
                $request->getChannel(),
            ),
            ChannelApprovalOutcome::Failed => ApproveChannelRegistrationResult::registrationFailed($request->getChannel()),
        };
    }

    private function markApproved(
        ChannelRegistrationRequest $request,
        ApproveChannelRegistration $command,
    ): ApproveChannelRegistrationResult {
        // The approval hands the channel to ChanServ, which writes the UDB C block;
        // that nested write clears the shared EntityManager and detaches $request.
        // Re-fetch a managed copy so save() updates the row instead of re-inserting it.
        $managed = $this->requestRepository->findByChannel($request->getChannelLower());
        if (null !== $managed) {
            $request = $managed;
        }

        $request->approve($command->approverNickname, $command->occurredAt);
        $this->requestRepository->save($request);

        return ApproveChannelRegistrationResult::approved($request->getChannel(), $request->getRequesterNick());
    }
}
