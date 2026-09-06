<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Verify;

final readonly class VerifyNick
{
    public function __construct(
        public string $nickname,
        public string $token,
        public string $senderUid,
    ) {}
}
