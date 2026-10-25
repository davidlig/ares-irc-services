<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Badmail;

use DateTimeImmutable;

final readonly class ManageBadmail
{
    public function __construct(
        public BadmailAction $action,
        public bool $authorized,
        public string $actor,
        public DateTimeImmutable $occurredAt,
        public string $domain = '',
        public string $reason = '',
        public ?int $id = null,
        public string $pattern = '*',
        public int $page = 1,
    ) {}
}
