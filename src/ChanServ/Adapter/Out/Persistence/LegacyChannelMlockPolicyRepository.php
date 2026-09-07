<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence;

use App\ChanServ\Application\Model\ChannelMlockPolicy;
use App\ChanServ\Application\Port\Out\ChannelMlockPolicyRepository;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelSetting;
use App\ChanServ\Domain\ValueObject\ModeName;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

final readonly class LegacyChannelMlockPolicyRepository implements ChannelMlockPolicyRepository
{
    public function __construct(private RegisteredChannelRepositoryInterface $channels) {}

    public function findByName(string $channelName): ?ChannelMlockPolicy
    {
        $channel = $this->channels->findByChannelName(strtolower($channelName));

        return null === $channel ? null : $this->snapshot($channel);
    }

    public function all(): array
    {
        return array_values(array_map($this->snapshot(...), $this->channels->listAll()));
    }

    private function snapshot(RegisteredChannel $channel): ChannelMlockPolicy
    {
        return new ChannelMlockPolicy(
            name: $channel->getName(),
            blocked: $channel->isBlocked(),
            modeLock: $this->modeLock($channel),
        );
    }

    private function modeLock(RegisteredChannel $channel): ChannelModeLock
    {
        if (!$channel->isMlockActive()) {
            return ChannelModeLock::inactive();
        }

        $settings = [];
        foreach (str_split($channel->getMlock()) as $letter) {
            if ('+' === $letter || '-' === $letter) {
                continue;
            }
            $settings[] = new ChannelSetting(new ModeName($letter), $channel->getMlockParam($letter));
        }

        return ChannelModeLock::active($settings);
    }
}
