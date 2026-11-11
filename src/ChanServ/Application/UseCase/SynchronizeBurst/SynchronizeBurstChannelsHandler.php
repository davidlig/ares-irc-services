<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SynchronizeBurst;

use App\ChanServ\Application\Model\BurstChannelPage;
use App\ChanServ\Application\Model\BurstRankEventScope;
use App\ChanServ\Application\Model\ChannelRankPolicy;
use App\ChanServ\Application\Port\In\ChannelRegistrationLifecycle;
use App\ChanServ\Application\Port\In\ForbiddenChannelEnforcement;
use App\ChanServ\Application\Port\Out\BurstChannelActivity;
use App\ChanServ\Application\Port\Out\BurstChannelPages;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelMlockPolicyRepository;
use App\ChanServ\Application\Port\Out\ChannelRankNetworkQuery;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\UseCase\ApplyStoredTopic\ApplyStoredChannelTopicHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlock;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlockHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementTrigger;
use App\ChanServ\Application\UseCase\EnforceNojoin\EnforceChannelNojoinHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanks;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanksHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementTrigger;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\ValueObject\ChannelLevelSet;
use DateTimeImmutable;

use function array_unique;
use function array_values;
use function strtolower;
use function usort;

final readonly class SynchronizeBurstChannelsHandler implements SynchronizeBurstChannels
{
    public function __construct(
        private BurstChannelPages $pages,
        private ChannelEntryNetworkQuery $entryNetwork,
        private ChanUserAccountPort $accounts,
        private ForbiddenChannelEnforcement $forbidden,
        private EnforceChannelAkickHandlerInterface $akicks,
        private EnforceChannelNojoinHandlerInterface $nojoin,
        private ChannelRankNetworkQuery $rankNetwork,
        private EnforceChannelRanksHandlerInterface $ranks,
        private ChannelMlockPolicyRepository $mlockPolicies,
        private EnforceChannelMlockHandlerInterface $mlock,
        private ApplyStoredChannelTopicHandlerInterface $topic,
        private ChannelRegistrationLifecycle $registration,
        private BurstRankEventScope $rankScope,
        private BurstChannelActivity $activity,
    ) {}

    public function handle(DateTimeImmutable $now): void
    {
        $registered = [];
        $eligible = [];
        foreach ($this->pages->pages() as $page) {
            $this->synchronizePage($page, $now, $registered, $eligible);
        }
        $this->registration->reconcileKnownChannels($registered, $eligible);
    }

    /** @param array<string, true> $registered
     * @param list<string> $eligible
     */
    private function synchronizePage(BurstChannelPage $page, DateTimeImmutable $now, array &$registered, array &$eligible): void
    {
        $akicks = [];
        foreach ($page->akicks as $akick) {
            $akicks[$akick->getChannelId()][] = $akick;
        }
        foreach ($akicks as &$channelAkicks) {
            usort($channelAkicks, static fn ($left, $right): int => $left->getCreatedAt() <=> $right->getCreatedAt());
        }
        unset($channelAkicks);
        $access = [];
        foreach ($page->access as $entry) {
            $access[$entry->getChannelId()][$entry->getNickId()] = $entry->getLevel();
        }
        $levels = [];
        foreach ($page->levels as $level) {
            $levels[$level->getChannelId()][$level->getLevelKey()] = $level->getValue();
        }

        $nicknames = [];
        $this->rankScope->setChannel(null, null);
        foreach ($page->channels as $channel) {
            $registered[strtolower($channel->getName())] = true;
            if ($channel->isForbidden()) {
                $this->forbidden->enforceKnownForbiddenChannel($channel->getName());
            }
            if ($channel->isBlocked()) {
                continue;
            }
            $eligible[] = $channel->getName();
            $view = $this->entryNetwork->findChannel($channel->getName());
            if (null !== $view) {
                foreach ($view->members as $member) {
                    $nicknames[] = $member->nickname;
                }
            }
        }
        $accounts = $this->accounts->findAccountsByNicks(array_values(array_unique($nicknames)));
        foreach ($page->channels as $channel) {
            if ($channel->isBlocked()) {
                continue;
            }
            $id = $channel->getId();
            $policy = new ChannelRankPolicy(
                $id,
                $channel->getName(),
                $channel->getFounderNickId(),
                $channel->isSecure(),
                false,
                ChannelLevelSet::fromOverrides($levels[$id] ?? []),
                $access[$id] ?? [],
            );
            $this->rankScope->setChannel($policy, $this->rankNetwork->findChannelWithAccounts($channel->getName(), $accounts));
            $view = $this->entryNetwork->findChannel($channel->getName());
            if (null !== $view) {
                $this->akicks->handleKnownChannelWithAkicks($channel, $view->members, $now, $akicks[$id] ?? []);
            }
            $view = $this->entryNetwork->findChannel($channel->getName());
            if (null !== $view) {
                $this->nojoin->handleKnownChannelWithSnapshot(
                    $channel,
                    $view->members,
                    $levels[$id][ChannelLevel::KEY_NOJOIN] ?? ChannelLevel::getDefault(ChannelLevel::KEY_NOJOIN),
                    $accounts,
                    $access[$id] ?? [],
                );
            }
            $rankState = $this->rankNetwork->findChannelWithAccounts($channel->getName(), $accounts);
            $this->ranks->handleKnownPolicyAndNetwork(new EnforceChannelRanks($channel->getName(), RankEnforcementTrigger::NetworkSynchronized), $policy, $rankState);
            $mlock = $this->mlockPolicies->forChannel($channel);
            if ($mlock->modeLock->active) {
                $this->mlock->handleKnownPolicy(new EnforceChannelMlock($channel->getName(), MlockEnforcementTrigger::NetworkSynchronized), $mlock);
            }
            $this->topic->synchronizeKnownChannel($channel);
        }
        $this->rankScope->setChannel(null, null);
        $activityIds = [];
        foreach ($page->channels as $channel) {
            if (!$channel->isBlocked() && $this->rankScope->needsActivity($channel->getName())) {
                $activityIds[] = $channel->getId();
            }
        }
        $this->activity->touchByIds($activityIds, $now);
    }
}
