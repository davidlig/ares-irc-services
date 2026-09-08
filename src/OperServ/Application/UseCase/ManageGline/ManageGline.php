<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGline;

use DateTimeImmutable;

final readonly class ManageGline
{
    public function __construct(
        public GlineAction $action,
        public string $actor,
        public ?int $actorAccountId,
        public DateTimeImmutable $occurredAt,
        public ?string $mask = null,
        public ?string $expiry = null,
        public ?string $reason = null,
        public ?string $listPattern = null,
    ) {}
}
