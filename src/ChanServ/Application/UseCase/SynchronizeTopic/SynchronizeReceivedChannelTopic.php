<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SynchronizeTopic;

use DateTimeImmutable;

final readonly class SynchronizeReceivedChannelTopic
{
    public function __construct(
        public string $channelName,
        public ?string $topic,
        public DateTimeImmutable $occurredAt,
        public ?string $setterNickname,
        public ?string $sourceUid,
    ) {}
}
