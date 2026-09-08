<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Service;

use App\ChanServ\Application\Port\In\RegisteredChannelSetup;
use App\ChanServ\Application\Port\Out\ChannelMlockPolicyRepository;
use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelSetupActions;

final readonly class RegisteredChannelSetupService implements RegisteredChannelSetup
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelMlockPolicyRepository $modeLockPolicies,
        private ChanNetworkActions $network,
        private RegisteredChannelSetupActions $actions,
    ) {}

    public function restore(string $channelName): void
    {
        $registered = $this->channels->findByChannelName(strtolower($channelName));
        if (null === $registered || $registered->isBlocked()) {
            return;
        }

        if (!$this->network->isChannelOnNetwork($channelName)) {
            $this->actions->restoreRegistrationModes($channelName);

            $modeLockPolicy = $this->modeLockPolicies->findByName($channelName);
            if (null !== $modeLockPolicy && $modeLockPolicy->modeLock->active) {
                $this->actions->restoreModeLock($channelName, $modeLockPolicy->modeLock);
            }

            $topic = $registered->getTopic();
            if (null !== $topic) {
                $this->actions->restoreTopic($channelName, $topic);
            }
        }

        $this->actions->restoreServiceRank($channelName);
    }
}
