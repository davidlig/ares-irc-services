<?php

declare(strict_types=1);

namespace App\MemoServ\Application\Model;

use DateTimeImmutable;

final readonly class MemoListItem
{
    public function __construct(
        public int $index,
        public string $senderDisplay,
        public DateTimeImmutable $createdAt,
        public string $preview,
        public bool $isRead,
    ) {}
}
