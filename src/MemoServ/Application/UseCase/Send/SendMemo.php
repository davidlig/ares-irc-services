<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Send;

final readonly class SendMemo
{
    public function __construct(
        public string $senderUid,
        public int $senderNickId,
        public string $target,
        public string $message,
    ) {}
}
