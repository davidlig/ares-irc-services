<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\GetPendingChannelNotice;

final readonly class GetPendingChannelNotice
{
    public function __construct(
        public string $uid,
        public string $nickname,
        public bool $isIdentified,
        public string $channelName,
    ) {}
}
