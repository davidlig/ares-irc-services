<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Read;

use DateTimeImmutable;

final readonly class ReadMemo
{
    public function __construct(
        public int $senderNickId,
        public ?string $channelName,
        public int $index,
        public DateTimeImmutable $occurredAt,
    ) {}
}
