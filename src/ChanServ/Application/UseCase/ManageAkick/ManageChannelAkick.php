<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAkick;

use DateTimeImmutable;

final readonly class ManageChannelAkick
{
    public function __construct(
        public string $channelName,
        public ManageChannelAkickAction $action,
        public ?int $actorNickId,
        public bool $founderEquivalent,
        public ?string $performedBy,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $now,
        public ?string $item = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?string $reason = null,
        public bool $validRequest = true,
    ) {}
}
