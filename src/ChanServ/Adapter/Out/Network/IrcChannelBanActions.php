<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Port\Out\ChannelBanActions;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;

final readonly class IrcChannelBanActions implements ChannelBanActions
{
    public function __construct(private ChannelServiceActionsPort $actions) {}

    public function removeBans(string $channelName, array $masks, ?int $channelTimestamp): void
    {
        foreach ($masks as $mask) {
            $this->actions->setChannelModes($channelName, '-b', [$mask], $channelTimestamp);
        }
    }
}
