<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\RemoveOwnAccess;

use DateTimeImmutable;

final readonly class RemoveOwnChannelAccess
{
    public function __construct(
        public string $channelName,
        public int $accountId,
        public string $nickname,
        public string $actorIp,
        public string $actorHost,
        public DateTimeImmutable $occurredAt,
        public bool $founderEquivalent = false,
    ) {}
}
