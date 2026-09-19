<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGline;

use DateTimeImmutable;

final readonly class GlineListEntry
{
    public function __construct(
        public string $mask,
        public ?string $reason,
        public ?string $creatorNickname,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
