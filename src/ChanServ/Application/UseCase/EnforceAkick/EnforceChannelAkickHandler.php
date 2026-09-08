<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceAkick;

use App\ChanServ\Application\Port\Out\AkickActions;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Policy\AkickMatchPolicy;
use App\ChanServ\Domain\ValueObject\AkickMask;
use App\ChanServ\Domain\ValueObject\AkickRule;
use DateTimeImmutable;

final readonly class EnforceChannelAkickHandler implements EnforceChannelAkickHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelAkickRepositoryInterface $akicks,
        private ChannelEntryNetworkQuery $network,
        private AkickActions $actions,
        private AkickMatchPolicy $matchPolicy,
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

        $rules = [];
        foreach ($this->akicks->listByChannel($channel->getId()) as $akick) {
            $rules[] = new AkickRule(
                new AkickMask($akick->getMask()),
                $akick->getReason(),
                $akick->getExpiresAt(),
            );
        }

        $enforced = 0;
        foreach ($members as $member) {
            $matching = $this->matchPolicy->firstMatch($rules, $member->userMask, $now, $member->operator);
            if (null === $matching) {
                continue;
            }

            $this->actions->banAndKick(
                $channel->getName(),
                $member->uid,
                $matching->mask->value,
                $matching->enforcementReason(),
            );
            ++$enforced;
        }

        return $enforced;
    }
}
