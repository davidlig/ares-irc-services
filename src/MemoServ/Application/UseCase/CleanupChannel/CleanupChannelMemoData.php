<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\CleanupChannel;

final readonly class CleanupChannelMemoData
{
    public function __construct(
        public int $channelId,
    ) {}
}
