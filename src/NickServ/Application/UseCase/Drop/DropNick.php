<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Drop;

use DateTimeImmutable;

final readonly class DropNick
{
    public function __construct(
        public string $targetNick,
        public string $operatorNick,
        public DateTimeImmutable $occurredAt,
        public bool $force = false,
        public bool $forceAllowed = false,
    ) {}
}
