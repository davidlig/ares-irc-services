<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceAkick;

use App\ChanServ\Application\Port\Out\AkickActions;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Policy\AkickMatchPolicy;
use App\ChanServ\Domain\ValueObject\AkickMask;
use App\ChanServ\Domain\ValueObject\AkickRule;
use DateTimeImmutable;
use InvalidArgumentException;

use function array_values;
use function spl_object_id;
use function sprintf;
use function strtolower;

final readonly class EnforceChannelAkickHandler implements EnforceChannelAkickHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelAkickRepositoryInterface $akicks,
        private ChannelEntryNetworkQuery $network,
        private AkickActions $actions,
        private AkickMatchPolicy $matchPolicy,
        private ChanServActivitySink $activity,
    ) {}

    public function handle(EnforceChannelAkick $command): int
    {
        $channel = $this->channels->findByChannelName(strtolower($command->channelName));
        if (null === $channel || $channel->isBlocked()) {
            return 0;
        }

        $member = $this->network->findMember($command->memberUid);
        if (null === $member) {
            return 0;
        }

        return $this->handleKnownChannel($channel, [$member], $command->now);
    }

    public function handleKnownChannel(RegisteredChannel $channel, array $members, DateTimeImmutable $now): int
    {
        if ($channel->isBlocked()) {
            return 0;
        }

        return $this->handleKnownChannelWithAkicks($channel, $members, $now, array_values($this->akicks->listByChannel($channel->getId())));
    }

    public function handleKnownChannelWithAkicks(RegisteredChannel $channel, array $members, DateTimeImmutable $now, array $akicks): int
    {
        if ($channel->isBlocked()) {
            return 0;
        }

        $rules = [];
        $ruleMap = [];
        foreach ($akicks as $akick) {
            try {
                $rule = new AkickRule(
                    new AkickMask($akick->getMask()),
                    $akick->getReason(),
                    $akick->getExpiresAt(),
                );
            } catch (InvalidArgumentException) {
                $this->activity->warning(sprintf('Skipping invalid AKICK rule for channel ID %d.', $channel->getId()));
                continue;
            }
            $rules[] = $rule;
            $ruleMap[spl_object_id($rule)] = $akick;
        }

        $enforced = 0;
        $used = [];
        try {
            foreach ($members as $member) {
                $matching = $this->matchPolicy->firstMatch($rules, $member->userMask, $now, $member->operator);
                if (null === $matching) {
                    continue;
                }

                if (isset($ruleMap[spl_object_id($matching)])) {
                    $matchedAkick = $ruleMap[spl_object_id($matching)];
                    $matchedAkick->recordUsage($now);
                    $used[spl_object_id($matchedAkick)] = $matchedAkick;
                }

                $this->actions->banAndKick(
                    $channel->getName(),
                    $member->uid,
                    $matching->mask->value,
                    $matching->enforcementReason(),
                );
                ++$enforced;
            }
        } finally {
            if ([] !== $used) {
                $this->akicks->saveAll(array_values($used));
            }
        }

        return $enforced;
    }
}
