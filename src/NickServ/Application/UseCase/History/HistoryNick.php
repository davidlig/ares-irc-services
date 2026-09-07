<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\History;

use DateTimeImmutable;

final readonly class HistoryNick
{
    public function __construct(
        public string $nickname,
        public HistoryNickAction $action,
        public DateTimeImmutable $occurredAt,
        public ?string $message = null,
        public ?int $entryId = null,
        public int $page = 1,
        public bool $showAll = false,
        public ?string $operatorNick = null,
        public ?int $operatorNickId = null,
        public ?string $operatorIp = null,
        public ?string $operatorHost = null,
    ) {}
}
