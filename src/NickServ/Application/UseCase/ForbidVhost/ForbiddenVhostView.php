<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\ForbidVhost;

use DateTimeImmutable;

final readonly class ForbiddenVhostView
{
    public function __construct(
        public string $pattern,
        public ?int $creatorNickId,
        public DateTimeImmutable $createdAt,
    ) {}
}
