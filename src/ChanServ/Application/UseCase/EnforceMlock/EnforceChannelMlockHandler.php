<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceMlock;

use App\ChanServ\Application\Model\ChannelMlockPolicy;
use App\ChanServ\Application\Port\Out\ChannelMlockNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelMlockPolicyRepository;
use App\ChanServ\Application\Port\Out\ChannelModeActions;
use App\ChanServ\Domain\Policy\MlockReconciliationPolicy;

use function count;

final readonly class EnforceChannelMlockHandler implements EnforceChannelMlockHandlerInterface
{
    public function __construct(
        private ChannelMlockPolicyRepository $channelPolicies,
        private ChannelMlockNetworkQuery $network,
        private ChannelModeActions $modeActions,
        private MlockReconciliationPolicy $reconciliationPolicy,
    ) {}

    public function handle(EnforceChannelMlock $command): MlockEnforcementResult
    {
        if (
            MlockEnforcementTrigger::ChannelSynchronized === $command->trigger
            && !$this->network->synchronizationComplete()
        ) {
            return new MlockEnforcementResult(MlockEnforcementOutcome::SynchronizationPending);
        }

        $channel = $this->channelPolicies->findByName($command->channelName);
        if (null === $channel) {
            return new MlockEnforcementResult(MlockEnforcementOutcome::ChannelUnavailable);
        }

        return $this->enforceKnownPolicy($channel);
    }

    public function handleKnownPolicy(EnforceChannelMlock $command, ChannelMlockPolicy $channel): MlockEnforcementResult
    {
        if (
            MlockEnforcementTrigger::ChannelSynchronized === $command->trigger
            && !$this->network->synchronizationComplete()
        ) {
            return new MlockEnforcementResult(MlockEnforcementOutcome::SynchronizationPending);
        }

        return $this->enforceKnownPolicy($channel);
    }

    private function enforceKnownPolicy(ChannelMlockPolicy $channel): MlockEnforcementResult
    {
        if ($channel->blocked) {
            return new MlockEnforcementResult(MlockEnforcementOutcome::ChannelBlocked);
        }
        if (!$channel->modeLock->active) {
            return new MlockEnforcementResult(MlockEnforcementOutcome::LockInactive);
        }

        $networkChannel = $this->network->findChannel($channel->name);
        if (null === $networkChannel) {
            return new MlockEnforcementResult(MlockEnforcementOutcome::ChannelUnavailable);
        }

        $changes = $this->reconciliationPolicy->reconcile(
            $channel->modeLock,
            $networkChannel->settings,
            $networkChannel->modeCapabilities,
        );
        if ([] === $changes) {
            return new MlockEnforcementResult(MlockEnforcementOutcome::NoChanges);
        }

        $this->modeActions->apply($channel->name, $changes);

        return new MlockEnforcementResult(MlockEnforcementOutcome::Applied, count($changes));
    }
}
