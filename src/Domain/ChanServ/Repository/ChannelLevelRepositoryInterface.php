<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Repository;

use App\Domain\ChanServ\Entity\ChannelLevel;

interface ChannelLevelRepositoryInterface
{
    public function save(ChannelLevel $level): void;

    public function findByChannelAndKey(int $channelId, string $levelKey): ?ChannelLevel;

    /** @return ChannelLevel[] */
    public function listByChannel(int $channelId): array;

    /** Remove all level overrides for the channel (used by LEVELS RESET). */
    public function removeAllForChannel(int $channelId): void;
}
