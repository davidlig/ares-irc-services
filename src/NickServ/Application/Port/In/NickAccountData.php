<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

final readonly class NickAccountData
{
    public function __construct(
        public int $id,
        public string $nickname,
        public string $language,
        public string $timezone = 'UTC',
    ) {}
}
