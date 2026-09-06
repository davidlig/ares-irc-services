<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Info;

final readonly class InfoNick
{
    public function __construct(
        public string $targetNick,
        public ?string $senderNick = null,
        public bool $senderIsIdentified = false,
        public bool $senderIsOper = false,
        public bool $targetIsOnlineAndIdentified = false,
    ) {}
}
