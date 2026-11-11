<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;

final readonly class BurstChannelPage
{
    /**
     * @param list<RegisteredChannel> $channels
     * @param list<ChannelAccess>     $access
     * @param list<ChannelLevel>      $levels
     * @param list<ChannelAkick>      $akicks
     */
    public function __construct(
        public array $channels,
        public array $access,
        public array $levels,
        public array $akicks,
    ) {}
}
