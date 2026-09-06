<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Status;

final readonly class StatusNick
{
    public function __construct(
        public string $nickname,
        public bool $isOnline = false,
        public bool $isIdentified = false,
    ) {}
}
