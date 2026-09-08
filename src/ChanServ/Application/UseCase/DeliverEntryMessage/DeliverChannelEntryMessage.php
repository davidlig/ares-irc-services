<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\DeliverEntryMessage;

final readonly class DeliverChannelEntryMessage
{
    public function __construct(
        public string $channelName,
        public string $targetUid,
    ) {}
}
