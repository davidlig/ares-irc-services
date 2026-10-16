<?php

declare(strict_types=1);

namespace App\CRegServ\Application\Model;

final readonly class CRegServAccountView
{
    public function __construct(
        public int $id,
        public string $nickname,
        public string $language,
        public string $timezone = 'UTC',
    ) {}
}
