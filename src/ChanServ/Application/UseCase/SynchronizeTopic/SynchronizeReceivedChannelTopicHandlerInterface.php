<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SynchronizeTopic;

interface SynchronizeReceivedChannelTopicHandlerInterface
{
    public function handle(SynchronizeReceivedChannelTopic $command): void;
}
