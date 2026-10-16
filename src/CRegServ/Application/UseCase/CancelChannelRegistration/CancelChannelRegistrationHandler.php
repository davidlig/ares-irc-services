<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\CancelChannelRegistration;

use App\CRegServ\Application\Port\Out\ChannelRegistrationRequestRepositoryInterface;

use function str_starts_with;

/**
 * Withdraws the requester's own channel registration request before an oper reviews it.
 *
 * Pulsar-style cancel semantics: only a request that is still pending can be cancelled, and
 * only by the account that submitted it. Approved and denied requests are the services
 * record of a decision, so they are refused and must be handled by APROBAR/DENEGAR/DROP
 * instead. A successful cancel removes the record and frees the channel name.
 */
final readonly class CancelChannelRegistrationHandler implements CancelChannelRegistrationHandlerInterface
{
    public function __construct(
        private ChannelRegistrationRequestRepositoryInterface $requestRepository,
    ) {}

    public function handle(CancelChannelRegistration $command): CancelChannelRegistrationResult
    {
        if (!str_starts_with($command->channelName, '#')) {
            return CancelChannelRegistrationResult::invalidChannel();
        }

        $request = $this->requestRepository->findByChannel($command->channelName);
        if (null === $request) {
            return CancelChannelRegistrationResult::noRequest($command->channelName);
        }

        if ($request->getRequesterAccountId() !== $command->requesterAccountId) {
            return CancelChannelRegistrationResult::notOwner($request->getChannel());
        }

        if (!$request->isPending()) {
            return CancelChannelRegistrationResult::notPending($request->getChannel());
        }

        $this->requestRepository->delete($request);

        return CancelChannelRegistrationResult::cancelled($request->getChannel());
    }
}
