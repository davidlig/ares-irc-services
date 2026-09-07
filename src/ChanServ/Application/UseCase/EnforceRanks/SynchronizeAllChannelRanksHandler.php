<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceRanks;

use App\ChanServ\Application\Port\Out\ChannelRankPolicyRepository;

final readonly class SynchronizeAllChannelRanksHandler
{
    public function __construct(
        private ChannelRankPolicyRepository $channelPolicies,
        private EnforceChannelRanksHandlerInterface $enforceChannelRanks,
    ) {}

    public function handle(): int
    {
        $changes = 0;
        foreach ($this->channelPolicies->all() as $channel) {
            if ($channel->blocked) {
                continue;
            }
            $result = $this->enforceChannelRanks->handleKnownPolicy(
                new EnforceChannelRanks($channel->name, RankEnforcementTrigger::NetworkSynchronized),
                $channel,
            );
            $changes += $result->changeCount;
        }

        return $changes;
    }
}
