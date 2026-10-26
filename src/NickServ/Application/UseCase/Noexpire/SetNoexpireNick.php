<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Noexpire;

use DateTimeImmutable;

final readonly class SetNoexpireNick
{
    public function __construct(
        public string $nickname,
        public bool $noexpire,
        public DateTimeImmutable $occurredAt,
        public string $actorNickname,
    ) {}
}
