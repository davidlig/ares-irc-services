<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Ignore;

final readonly class IgnoreMemo
{
    public function __construct(
        public int $senderNickId,
        public IgnoreMemoAction $action,
        public ?string $channelName = null,
        public ?string $targetNick = null,
    ) {}
}
