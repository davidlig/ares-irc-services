<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\List;

final readonly class ListMemos
{
    public function __construct(
        public int $senderNickId,
        public string $senderNickName,
        public ?string $channelName = null,
    ) {}
}
