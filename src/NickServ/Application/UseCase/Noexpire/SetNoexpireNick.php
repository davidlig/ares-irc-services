<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Noexpire;

final readonly class SetNoexpireNick
{
    public function __construct(
        public string $nickname,
        public bool $noexpire,
    ) {}
}
