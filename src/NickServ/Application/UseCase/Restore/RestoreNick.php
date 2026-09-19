<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Restore;

final readonly class RestoreNick
{
    public function __construct(
        public string $nickname,
        public string $operatorNick,
    ) {}
}
