<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;

interface RescueCredentialPersistence
{
    public function confirm(int $accountId, NickStatus $expectedStatus, ?string $expectedHash, string $newHash, DateTimeImmutable $now): bool;

    public function rotateAfterRecovery(int $accountId, string $expectedHash, string $newPasswordHash, string $newTokenHash, DateTimeImmutable $now): bool;

    public function replacePending(int $accountId, ?string $expectedHash, string $newHash, DateTimeImmutable $now): bool;
}
