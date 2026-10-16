<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\DenyChannelRegistration;

use App\CRegServ\Application\Port\Out\ChannelRegistrationRequestRepositoryInterface;
use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;

use function str_starts_with;

/**
 * Denies a pending channel registration request, keeping the channel name free.
 *
 * The denial stores who denied it, when and why, mirroring pulsar's do_denegar
 * (modules/cregserv/main.c:656-681), which requires a motivo and refuses a request that
 * has no record in flight, is already approved, or is already denied.
 */
final readonly class DenyChannelRegistrationHandler implements DenyChannelRegistrationHandlerInterface
{
    public function __construct(
        private ChannelRegistrationRequestRepositoryInterface $requestRepository,
    ) {}

    public function handle(DenyChannelRegistration $command): DenyChannelRegistrationResult
    {
        if (!str_starts_with($command->channelName, '#')) {
            return DenyChannelRegistrationResult::invalidChannel($command->channelName);
        }

        $request = $this->requestRepository->findByChannel($command->channelName);
        if (null === $request) {
            return DenyChannelRegistrationResult::noPendingRequest($command->channelName);
        }

        if (ChannelRegistrationStatus::Approved === $request->getStatus()) {
            return DenyChannelRegistrationResult::alreadyApproved($request->getChannel());
        }

        if (ChannelRegistrationStatus::Denied === $request->getStatus()) {
            return DenyChannelRegistrationResult::alreadyDenied($request->getChannel());
        }

        $request->deny($command->denierNickname, $command->reason, $command->occurredAt);
        $this->requestRepository->save($request);

        return DenyChannelRegistrationResult::denied($request->getChannel(), $command->reason);
    }
}
