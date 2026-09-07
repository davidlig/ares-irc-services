<?php

declare(strict_types=1);

namespace App\MemoServ\Application\Model;

final readonly class MemoAccountView
{
    public function __construct(
        public int $id,
        public string $nickname,
        public string $language,
        public string $timezone = 'UTC',
    ) {}
}
