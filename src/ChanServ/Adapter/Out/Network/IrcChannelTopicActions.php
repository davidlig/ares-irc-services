<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Port\Out\ChannelTopicActions;
use App\Shared\Application\Port\ChannelServiceActionsPort;

final readonly class IrcChannelTopicActions implements ChannelTopicActions
{
    public function __construct(private ChannelServiceActionsPort $actions) {}

    public function setTopic(string $channelName, ?string $topic): void
    {
        $this->actions->setChannelTopic($channelName, $topic);
    }
}
