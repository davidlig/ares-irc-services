<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SaregisterChannel;

use DateTimeImmutable;

final readonly class SaregisterChannel
{
    public function __construct(
        public string $channelName,
        public string $targetNickname,
        public string $description,
        public string $actorNickname,
        public ?int $actorAccountId,
        public bool $actorIrcOperator,
        public DateTimeImmutable $occurredAt,
    ) {}
}
