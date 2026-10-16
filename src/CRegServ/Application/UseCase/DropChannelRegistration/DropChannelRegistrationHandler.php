<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\DropChannelRegistration;

use App\CRegServ\Application\Port\Out\ChannelRegistrationRequestRepositoryInterface;

use function str_starts_with;

/**
 * Removes CReG's own record for a channel.
 *
 * Mirrors pulsar's do_drop (modules/cregserv/main.c:682-699): the record goes away whatever
 * state it is in, and nothing outside CReG changes — an approved channel stays registered
 * with ChanServ, because the C block was written there and is not ours to remove.
 *
 * Dropping is the only way out of a denial: the unique record is what blocks the channel
 * name, so without this the name could never be requested again.
 */
final readonly class DropChannelRegistrationHandler implements DropChannelRegistrationHandlerInterface
{
    public function __construct(
        private ChannelRegistrationRequestRepositoryInterface $requestRepository,
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

        $this->requestRepository->delete($request);

        return DropChannelRegistrationResult::dropped($request->getChannel());
    }
}
