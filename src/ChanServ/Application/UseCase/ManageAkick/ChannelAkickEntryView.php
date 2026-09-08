<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAkick;

use DateTimeImmutable;

final readonly class ChannelAkickEntryView
{
    public function __construct(
        public string $mask,
        public ?string $reason,
        public ?string $creatorNickname,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
