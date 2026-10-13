<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Saregister;

use DateTimeImmutable;

final readonly class Saregister
{
    public function __construct(
        public string $nickname,
        public string $password,
        public string $email,
        public string $language,
        public string $actorNickname,
        public ?int $actorAccountId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
