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
use Doctrine\ORM\EntityManagerInterface;

use function strtolower;

final readonly class DoctrineChannelRankPolicyRepository implements ChannelRankPolicyRepository
{
    private ChannelActivityBuffer $activityBuffer;

    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelAccessRepositoryInterface $access,
        private ChannelLevelRepositoryInterface $levels,
        private EntityManagerInterface $em,
        ?ChannelActivityBuffer $activityBuffer = null,
    ) {
        $this->activityBuffer = $activityBuffer ?? new ChannelActivityBuffer($this->em->getConnection());
    }

    public function findByName(string $channelName): ?ChannelRankPolicy
    {
        $channel = $this->channels->findByChannelName(strtolower($channelName));

        return null === $channel ? null : $this->snapshot($channel, false);
    }

    public function all(): iterable
    {
        foreach ($this->channels->iterateAll() as $channel) {
            yield $this->snapshot($channel, true);
        }
    }

    public function findByNameForNick(string $channelName, ?int $nickId): ?ChannelRankPolicy
    {
        $channel = $this->channels->findByChannelName(strtolower($channelName));

        return null === $channel ? null : $this->snapshot($channel, false, true, $nickId);
    }

    public function touchLastUsed(int $channelId): void
    {
        $this->activityBuffer->record($channelId, new DateTimeImmutable());
    }

    private function snapshot(RegisteredChannel $channel, bool $detachComponents, bool $singleMember = false, ?int $nickId = null): ChannelRankPolicy
    {
        $levelOverrides = [];
        foreach ($this->levels->listByChannel($channel->getId()) as $level) {
            $levelOverrides[$level->getLevelKey()] = $level->getValue();
            if ($detachComponents) {
                $this->em->detach($level);
            }
        }

        $accessByNickId = [];
        $entry = $singleMember && null !== $nickId ? $this->access->findByChannelAndNick($channel->getId(), $nickId) : null;
        $entries = $singleMember ? (null === $entry ? [] : [$entry]) : $this->access->listByChannel($channel->getId());
        foreach ($entries as $entry) {
            $accessByNickId[$entry->getNickId()] = $entry->getLevel();
            if ($detachComponents) {
                $this->em->detach($entry);
            }
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
