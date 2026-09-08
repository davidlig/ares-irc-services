<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageMotd;

use DateTimeImmutable;

final readonly class ManageMotd
{
    public function __construct(
        public MotdAction $action,
        public string $actor,
        public ?int $actorAccountId,
        public DateTimeImmutable $occurredAt,
        public ?string $botNickname = null,
        public ?string $messageType = null,
        public ?string $expiry = null,
        public ?string $text = null,
        public ?string $id = null,
    ) {}
}
