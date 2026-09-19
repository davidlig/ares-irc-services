<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageLifecycle;

use DateTimeImmutable;

final readonly class ChannelLifecycleResult
{
    public function __construct(
        public ChannelLifecycleOutcome $outcome,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}
}
