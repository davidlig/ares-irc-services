<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use DateTimeImmutable;

final readonly class GlineEntry
{
    public function __construct(
        public string $mask,
        public ?int $creatorAccountId,
        public ?string $reason,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $expiresAt,
    ) {}

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt < $now;
    }
}
