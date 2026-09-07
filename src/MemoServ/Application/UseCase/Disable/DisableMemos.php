<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Disable;

final readonly class DisableMemos
{
    public function __construct(
        public int $senderNickId,
        public ?string $channelName = null,
    ) {}
}
