<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\TransferFounder;

final readonly class TransferChannelFounderResult
{
    public function __construct(
        public TransferFounderOutcome $outcome,
        public ?string $targetNickname = null,
        public ?string $emailHint = null,
        public int $maximumChannelsPerNick = 0,
    ) {}
}
