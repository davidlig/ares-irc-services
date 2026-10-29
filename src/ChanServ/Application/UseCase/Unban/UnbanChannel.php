<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\Unban;

final readonly class UnbanChannel
{
    public function __construct(
        public string $channelName,
        public int $accountId,
        public string $requesterUid,
        public bool $founderEquivalent,
    ) {}
}
