<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Service;

use App\ChanServ\Application\Port\In\ChannelRegistrationLifecycle;
use App\ChanServ\Application\Port\Out\ChannelRegistrationNetworkActions;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;

use function strtolower;

final readonly class ChannelRegistrationService implements ChannelRegistrationLifecycle
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelRegistrationNetworkActions $networkActions,
    ) {}

    public function channelRegistered(string $channelName): void
    {
        $this->networkActions->applyRegistrationModes($channelName);
    }

    public function channelDropped(string $channelName, string $reason): void
    {
        $this->networkActions->removeRegistrationModesAfterDrop($channelName, $reason);
    }

    public function channelSynchronized(string $channelName): void
    {
        $registered = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $registered || $registered->isBlocked()) {
            return;
        }

        $this->networkActions->ensureRegisteredModeOnChannelSync($channelName);
    }

    public function reconcileRegisteredMode(): void
    {
        if (!$this->networkActions->supportsRegisteredMode()) {
            return;
        }

        [$registeredNames, $eligibleChannelNames] = $this->registrationSets();
        $this->networkActions->reconcileRegisteredMode($registeredNames, $eligibleChannelNames);
    }

    public function reconcilePermanentMode(): void
    {
        if (!$this->networkActions->supportsPermanentMode()) {
            return;
        }

        [$registeredNames, $eligibleChannelNames] = $this->registrationSets();
        $this->networkActions->reconcilePermanentMode($registeredNames, $eligibleChannelNames);
    }

    /**
     * @return array{array<string, true>, list<string>}
     */
    private function registrationSets(): array
    {
        $registeredNames = [];
        $eligibleChannelNames = [];

        foreach ($this->channelRepository->iterateAll() as $channel) {
            $registeredNames[strtolower($channel->getName())] = true;
            if (!$channel->isBlocked()) {
                $eligibleChannelNames[] = $channel->getName();
            }
        }

        return [$registeredNames, $eligibleChannelNames];
    }
}
