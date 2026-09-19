<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

final readonly class ChanAccountView
{
    public function __construct(
        public int $id,
        public string $nickname,
        public string $language,
        public string $timezone = 'UTC',
        public bool $registered = true,
        public bool $suspended = false,
        public ?string $email = null,
    ) {}
}
