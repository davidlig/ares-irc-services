<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In;

use DateTimeImmutable;

final readonly class GlineProjection
{
    public function __construct(
        public string $mask,
        public ?string $reason,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
