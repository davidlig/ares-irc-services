<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Service;

use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;

final readonly class ChannelRegistrationActivator
{
    public function __construct(
        private ChannelLevelRepositoryInterface $levels,
        private ChannelAkickRepositoryInterface $akicks,
    ) {}

    public function activate(RegisteredChannel $channel): void
    {
        $this->levels->removeAllForChannel($channel->getId());
        $this->akicks->deleteByChannelId($channel->getId());
        foreach (ChannelLevel::DEFAULTS as $key => $value) {
            $this->levels->save(new ChannelLevel($channel->getId(), $key, $value));
        }
    }
}
