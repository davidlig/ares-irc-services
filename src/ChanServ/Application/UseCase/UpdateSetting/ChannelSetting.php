<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\UpdateSetting;

enum ChannelSetting
{
    case Description;
    case Email;
    case EntryMessage;
    case Successor;
    case TopicLock;
    case Url;
}
