<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Identify;

final readonly class IdentifyNick
{
    public function __construct(
        public string $nickname,
        public string $password,
        public string $clientKey,
        public string $senderUid,
        public string $senderNick,
        public bool $senderIsIdentified = false,
    ) {}
}
