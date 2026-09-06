<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Drop;

final readonly class DropNick
{
    public function __construct(
        public string $targetNick,
        public string $operatorNick,
        public bool $force = false,
        public bool $forceAllowed = false,
    ) {}
}
