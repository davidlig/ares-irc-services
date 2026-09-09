<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAccess;

use DateTimeImmutable;

final readonly class ManageChannelAccess
{
    public function __construct(
        public string $channelName,
        public ManageChannelAccessAction $action,
        public ?int $actorNickId,
        public bool $founderEquivalent,
        public string $performedBy,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $occurredAt,
        public ?string $targetNickname = null,
        public ?int $level = null,
    ) {}
}
