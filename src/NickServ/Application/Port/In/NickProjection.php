<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

use DateTimeImmutable;

final readonly class NickProjection
{
    public function __construct(
        public int $id,
        public string $nickname,
        public ?string $passwordHash,
        public ?string $vhost,
        public bool $forbidden,
        public ?string $forbiddenReason,
        public bool $pendingVerification,
        public bool $suspended = false,
        public ?string $suspensionReason = null,
        public ?DateTimeImmutable $suspendedUntil = null,
    ) {}
}
