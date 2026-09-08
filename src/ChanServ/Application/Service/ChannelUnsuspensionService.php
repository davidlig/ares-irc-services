<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Service;

use App\ChanServ\Application\Port\In\UnsuspendedChannelRestoration;
use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlock;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlockHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementTrigger;

use function sprintf;

final readonly class ChannelUnsuspensionService implements UnsuspendedChannelRestoration
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanNetworkActions $channelActions,
        private ChannelSuspensionService $suspensionService,
        private EnforceChannelMlockHandlerInterface $enforceChannelMlock,
        private ChanServActivitySink $logger,
    ) {}

    public function restore(string $channelNameLower): ?string
    {
        $channel = $this->channelRepository->findByChannelName($channelNameLower);
        if (null === $channel) {
            return null;
        }

        $channelName = $channel->getName();
        if (!$this->channelActions->isChannelOnNetwork($channelName)) {
            $this->logger->info(sprintf(
                'ChanServUnsuspend: channel %s not found on network, joining to recreate it',
                $channelName,
            ));
            $this->channelActions->joinChannelAsService($channelName);
        }

        $this->suspensionService->liftSuspension($channel);
        $this->enforceChannelMlock->handle(new EnforceChannelMlock(
            $channelName,
            MlockEnforcementTrigger::ChannelUnsuspended,
        ));

        return $channelName;
    }
}
