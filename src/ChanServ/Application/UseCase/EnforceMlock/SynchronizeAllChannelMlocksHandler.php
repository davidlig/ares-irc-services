<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceMlock;

use App\ChanServ\Application\Port\Out\ChannelMlockPolicyRepository;

final readonly class SynchronizeAllChannelMlocksHandler
{
    public function __construct(
        private ChannelMlockPolicyRepository $channelPolicies,
        private EnforceChannelMlockHandlerInterface $enforceChannelMlock,
    ) {}

    public function handle(): int
    {
        $changes = 0;
        foreach ($this->channelPolicies->all() as $channel) {
            if ($channel->blocked || !$channel->modeLock->active) {
                continue;
            }
            $result = $this->enforceChannelMlock->handleKnownPolicy(
                new EnforceChannelMlock($channel->name, MlockEnforcementTrigger::NetworkSynchronized),
                $channel,
            );
            $changes += $result->changeCount;
        }

        return $changes;
    }
}
