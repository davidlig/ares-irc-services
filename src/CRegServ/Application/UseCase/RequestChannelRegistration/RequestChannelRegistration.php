<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\RequestChannelRegistration;

use DateTimeImmutable;

final readonly class RequestChannelRegistration
{
    public function __construct(
        public string $channelName,
        public string $description,
        public int $requesterAccountId,
        public string $requesterNickname,
        public bool $actorIrcOperator,
        public DateTimeImmutable $occurredAt,
    ) {}
}
