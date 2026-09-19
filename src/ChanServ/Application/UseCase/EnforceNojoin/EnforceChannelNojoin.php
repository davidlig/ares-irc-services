<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceNojoin;

final readonly class EnforceChannelNojoin
{
    public function __construct(
        public string $channelName,
        public string $memberUid,
    ) {}
}
