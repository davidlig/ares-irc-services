<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence;

use App\ChanServ\Application\Model\ChannelRankPolicy;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelRankPolicyRepository;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelLevelSet;
use DateTimeImmutable;

final readonly class DoctrineChannelRankPolicyRepository implements ChannelRankPolicyRepository
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelAccessRepositoryInterface $access,
        private ChannelLevelRepositoryInterface $levels,
    ) {}

    public function findByName(string $channelName): ?ChannelRankPolicy
    {
        $channel = $this->channels->findByChannelName(strtolower($channelName));

        return null === $channel ? null : $this->snapshot($channel);
    }

    public function all(): array
    {
        return array_values(array_map($this->snapshot(...), $this->channels->listAll()));
    }

    public function touchLastUsed(int $channelId): void
    {
        $channel = $this->channels->findByIds([$channelId])[0] ?? null;
        if (null === $channel) {
            return;
        }

        $channel->touchLastUsed(new DateTimeImmutable());
        $this->channels->save($channel);
    }

    private function snapshot(RegisteredChannel $channel): ChannelRankPolicy
    {
        $levelOverrides = [];
        foreach ($this->levels->listByChannel($channel->getId()) as $level) {
            $levelOverrides[$level->getLevelKey()] = $level->getValue();
        }

        $accessByNickId = [];
        foreach ($this->access->listByChannel($channel->getId()) as $entry) {
            $accessByNickId[$entry->getNickId()] = $entry->getLevel();
        }

        return new ChannelRankPolicy(
            id: $channel->getId(),
            name: $channel->getName(),
            founderNickId: $channel->getFounderNickId(),
            secure: $channel->isSecure(),
            blocked: $channel->isBlocked(),
            levels: ChannelLevelSet::fromOverrides($levelOverrides),
            accessByNickId: $accessByNickId,
        );
    }
}
