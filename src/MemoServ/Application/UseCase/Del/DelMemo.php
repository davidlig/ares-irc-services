<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Del;

final readonly class DelMemo
{
    public function __construct(
        public int $senderNickId,
        public ?string $channelName,
        public int $index,
    ) {}
}
