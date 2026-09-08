<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ApplyStoredTopic;

final readonly class ApplyStoredChannelTopic
{
    public function __construct(
        public StoredTopicApplicationTrigger $trigger,
        public string $channelName = '',
        public bool $channelSetupApplicable = false,
    ) {}
}
