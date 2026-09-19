<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In\Command;

use App\Shared\Application\Audit\SafeAuditMetadata;

final readonly class IrcopAuditData
{
    /** @var array<string, bool|float|int|string|null> */
    public array $extra;

    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $target,
        public ?string $targetHost = null,
        public ?string $targetIp = null,
        public ?string $reason = null,
        array $extra = [],
    ) {
        $this->extra = SafeAuditMetadata::validate($extra);
    }
}
