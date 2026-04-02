<?php

declare(strict_types=1);

namespace App\Application\Command;

final readonly class IrcopAuditData
{
    public function __construct(
        public string $target,
        public ?string $targetHost = null,
        public ?string $targetIp = null,
        public ?string $reason = null,
        public array $extra = [],
    ) {
    }
}
