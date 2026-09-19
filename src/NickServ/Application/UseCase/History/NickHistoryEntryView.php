<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\History;

use DateTimeImmutable;

final readonly class NickHistoryEntryView
{
    /**
     * @param array<string, mixed> $extraData
     */
    public function __construct(
        public int $id,
        public DateTimeImmutable $performedAt,
        public string $action,
        public string $performedBy,
        public ?int $performedByNickId,
        public bool $operatorExists,
        public string $message,
        public array $extraData = [],
    ) {}
}
