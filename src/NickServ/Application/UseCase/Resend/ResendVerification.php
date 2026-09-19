<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Resend;

final readonly class ResendVerification
{
    public function __construct(
        public string $nickname,
        public string $language,
    ) {}
}
