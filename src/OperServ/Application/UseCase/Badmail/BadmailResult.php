<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Badmail;

final readonly class BadmailResult
{
    /** @param list<BadmailEntryView> $entries */
    public function __construct(
        public BadmailOutcome $outcome,
        public ?string $domain = null,
        public array $entries = [],
        public int $total = 0,
        public int $page = 1,
        public int $pageSize = 50,
    ) {}
}
