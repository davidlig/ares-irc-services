<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\GetChannelRegistrationStatus;

use App\CRegServ\Application\Port\Out\ChannelRegistrationRequestRepositoryInterface;
use App\CRegServ\Application\Port\Out\RegisteredChannelLookupPort;

/**
 * Reports where a channel name stands in the registration workflow.
 *
 * Mirrors pulsar's do_estado (modules/cregserv/main.c:534-577): a channel already held by
 * ChanServ wins over any queued request, otherwise the queued record answers "in process",
 * "denied" (with the oper's reason) or "no registration process at all".
 */
final readonly class GetChannelRegistrationStatusHandler implements GetChannelRegistrationStatusHandlerInterface
{
    public function __construct(
        private ChannelRegistrationRequestRepositoryInterface $requestRepository,
        private RegisteredChannelLookupPort $channelLookup,
    ) {}

    public function handle(GetChannelRegistrationStatus $command): GetChannelRegistrationStatusResult
    {
        if ($this->channelLookup->existsByChannelName($command->channelName)) {
            return GetChannelRegistrationStatusResult::registered();
        }

        $request = $this->requestRepository->findByChannel($command->channelName);
        if (null === $request) {
            return GetChannelRegistrationStatusResult::noRequest();
        }

        if ($request->isApproved()) {
            return GetChannelRegistrationStatusResult::registered();
        }

        if ($request->isDenied()) {
            return GetChannelRegistrationStatusResult::denied($request->getDeniedReason(), $request->getDeniedAt());
        }

        return GetChannelRegistrationStatusResult::pending($request->getCreatedAt());
    }
}
