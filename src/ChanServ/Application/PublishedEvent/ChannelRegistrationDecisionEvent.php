<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use DateTimeImmutable;

/** Published after persistence succeeds; null restrictedUntil on rejection means indefinite. */
final readonly class ChannelRegistrationDecisionEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public int $requesterAccountId,
        public int $operatorAccountId,
        public string $operatorNickname,
        public bool $accepted,
        public ?string $rejectionReason,
        public ?DateTimeImmutable $restrictedUntil,
        public DateTimeImmutable $occurredAt,
    ) {}
}
