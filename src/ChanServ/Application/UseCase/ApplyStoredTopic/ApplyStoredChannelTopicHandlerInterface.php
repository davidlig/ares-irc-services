<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ApplyStoredTopic;

interface ApplyStoredChannelTopicHandlerInterface
{
    public function handle(ApplyStoredChannelTopic $command): void;
}
