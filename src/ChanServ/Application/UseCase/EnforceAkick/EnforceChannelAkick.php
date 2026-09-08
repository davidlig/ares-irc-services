<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceAkick;

use DateTimeImmutable;

final readonly class EnforceChannelAkick
{
    public function __construct(
        public string $channelName,
        public string $memberUid,
        public DateTimeImmutable $now,
    ) {}
}
