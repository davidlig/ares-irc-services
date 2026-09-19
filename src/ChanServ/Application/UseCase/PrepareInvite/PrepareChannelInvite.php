<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\PrepareInvite;

final readonly class PrepareChannelInvite
{
    public function __construct(
        public string $channelName,
        public int $accountId,
        public bool $founderEquivalent,
    ) {}
}
