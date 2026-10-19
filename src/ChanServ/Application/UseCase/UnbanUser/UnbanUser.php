<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\UnbanUser;

final readonly class UnbanUser
{
    public function __construct(
        public string $channelName,
        public int $accountId,
        public bool $founderEquivalent,
    ) {}
}
