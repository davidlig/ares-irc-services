<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In\Command;

final readonly class CommandOutcome
{
    private function __construct(
        public bool $success,
        public ?IrcopAuditData $auditData,
    ) {}

    public static function success(?IrcopAuditData $auditData = null): self
    {
        return new self(true, $auditData);
    }

    public static function rejected(): self
    {
        return new self(false, null);
    }
}
