<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ApplyStoredTopic;

use App\ChanServ\Domain\Entity\RegisteredChannel;

interface ApplyStoredChannelTopicHandlerInterface
{
    public function handle(ApplyStoredChannelTopic $command): void;

    public function synchronizeKnownChannel(RegisteredChannel $channel): void;
}
