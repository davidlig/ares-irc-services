<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\DropChannelRegistration;

use App\CRegServ\Application\Port\Out\ChannelDropOutcome;
use App\CRegServ\Application\Port\Out\ChannelDropPort;
use App\CRegServ\Application\Port\Out\ChannelRegistrationRequestRepositoryInterface;
use App\CRegServ\Application\Port\Out\RegisteredChannelLookupPort;

use function str_starts_with;

/**
 * Removes CReG's own record for a channel, and unregisters the channel from ChanServ when
 * it is registered there.
 *
 * Mirrors pulsar's do_drop (modules/cregserv/main.c:682-699), where CReG owned the
 * registration and del_creginfo() also unregistered the channel. In ares CReG delegates
 * registration to ChanServ (which writes the UDB C block), so an approved channel must be
 * dropped there too; a pending or denied request has no ChanServ registration to remove.
 *
 * Dropping is the only way out of a denial: the unique record is what blocks the channel
 * name, so without this the name could never be requested again.
 */
final readonly class DropChannelRegistrationHandler implements DropChannelRegistrationHandlerInterface
{
    public function __construct(
        private ChannelRegistrationRequestRepositoryInterface $requestRepository,
        private RegisteredChannelLookupPort $channelLookup,
        private ChannelDropPort $channelDrop,
    ) {}

    public function handle(DropChannelRegistration $command): DropChannelRegistrationResult
    {
        if (!str_starts_with($command->channelName, '#')) {
            return DropChannelRegistrationResult::invalidChannel($command->channelName);
        }

        $request = $this->requestRepository->findByChannel($command->channelName);
        if (null === $request) {
            return DropChannelRegistrationResult::noRequest($command->channelName);
        }

        $unregistered = false;
        if ($this->channelLookup->existsByChannelName($command->channelName)) {
            $unregistered = ChannelDropOutcome::Dropped === $this->channelDrop->drop(
                $command->channelName,
                $command->actorNickname,
                $command->occurredAt,
            );
        }

        // The nested ChanServ drop clears the shared EntityManager, detaching $request.
        // Re-fetch a managed copy so delete() can remove the row.
        $managed = $this->requestRepository->findByChannel($command->channelName);
        if (null !== $managed) {
            $request = $managed;
        }

        $this->requestRepository->delete($request);

        return $unregistered
            ? DropChannelRegistrationResult::droppedAndUnregistered($command->channelName)
            : DropChannelRegistrationResult::dropped($command->channelName);
    }
}
