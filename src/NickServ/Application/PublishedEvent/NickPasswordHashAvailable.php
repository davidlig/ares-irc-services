<?php

declare(strict_types=1);

namespace App\NickServ\Application\PublishedEvent;

final readonly class NickPasswordHashAvailable
{
    public function __construct(
        public ?int $nickId,
        public string $nickname,
        public ?string $passwordHash,
    ) {}
}
