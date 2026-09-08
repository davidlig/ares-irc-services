<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SynchronizeTopic;

final readonly class SynchronizeReceivedChannelTopic
{
    public function __construct(
        public string $channelName,
        public ?string $topic,
        public ?string $setterNickname,
        public ?string $sourceUid,
    ) {}
}
