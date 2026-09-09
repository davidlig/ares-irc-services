<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Forbid;

final readonly class ForbidNick
{
    public function __construct(
        public string $actorNickname,
        public string $targetNickname,
        public string $reason,
    ) {}
}
