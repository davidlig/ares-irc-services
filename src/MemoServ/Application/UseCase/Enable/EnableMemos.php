<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Enable;

final readonly class EnableMemos
{
    public function __construct(
        public int $senderNickId,
        public ?string $channelName = null,
    ) {}
}
