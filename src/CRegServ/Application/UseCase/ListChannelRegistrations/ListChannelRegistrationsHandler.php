<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\ListChannelRegistrations;

use App\CRegServ\Application\Port\Out\ChannelRegistrationRequestRepositoryInterface;
use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;

use function array_map;

/**
 * Reads one work queue for a services oper.
 *
 * The two pulsar listings, do_pendientes and do_denegados, are the same read over the two
 * statuses a request can be waiting in, so this is one use case the two commands present
 * differently rather than two copies of the same query.
 */
final readonly class ListChannelRegistrationsHandler implements ListChannelRegistrationsHandlerInterface
{
    public function __construct(
        private ChannelRegistrationRequestRepositoryInterface $requestRepository,
    ) {}

    public function handle(ListChannelRegistrations $command): ListChannelRegistrationsResult
    {
        return new ListChannelRegistrationsResult(array_map(
            static fn (ChannelRegistrationRequest $request): ChannelRegistrationRow => new ChannelRegistrationRow(
                channel: $request->getChannel(),
                requesterNick: $request->getRequesterNick(),
                description: $request->getDescription(),
                createdAt: $request->getCreatedAt(),
                deniedReason: $request->getDeniedReason(),
                deniedAt: $request->getDeniedAt(),
            ),
            $this->requestRepository->findByStatuses([$command->status]),
        ));
    }
}
