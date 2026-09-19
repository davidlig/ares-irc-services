<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Suspend;

use DateTimeImmutable;

final readonly class SuspendNickResult
{
    public function __construct(
        public SuspendNickOutcome $outcome,
        public string $targetNickname,
        public string $duration,
        public string $reason,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}
}
