<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\RegisterChannel;

use DateTimeImmutable;

final readonly class RegisterChannel
{
    public function __construct(
        public string $channelName,
        public string $description,
        public ?int $accountId,
        public string $actorNickname,
        public bool $actorIdentified,
        public bool $actorIrcOperator,
        public bool $channelExistsOnNetwork,
        public bool $hasRequiredChannelRank,
        public DateTimeImmutable $occurredAt,
    ) {}
}
