<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface ChannelTopicActions
{
    public function setTopic(string $channelName, ?string $topic): void;
}
