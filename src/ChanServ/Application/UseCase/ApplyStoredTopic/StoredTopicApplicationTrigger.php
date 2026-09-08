<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ApplyStoredTopic;

enum StoredTopicApplicationTrigger
{
    case ChannelSynchronized;
    case NetworkSynchronizationCompleted;
}
