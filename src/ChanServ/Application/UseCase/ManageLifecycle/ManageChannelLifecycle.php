<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageLifecycle;

use DateTimeImmutable;

final readonly class ManageChannelLifecycle
{
    public function __construct(
        public string $channelName,
        public ChannelLifecycleAction $action,
        public string $actorNickname,
        public DateTimeImmutable $occurredAt,
        public ?int $actorAccountId = null,
        public string $actorIp = '*',
        public string $actorHost = '*',
        public ?string $reason = null,
        public ?string $duration = null,
        public bool $force = false,
    ) {}
}
