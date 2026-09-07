<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use DateTimeImmutable;

/**
 * Tracks UIDs with pending nick protection checks.
 *
 * When an unidentified user joins or changes nick to a registered nickname,
 * a short grace period is scheduled before forcing a guest rename.
 * If the IRCd authenticates the user (e.g. UDB / SASL) and emits +r mode,
 * the pending check is cancelled, avoiding false guest renames.
 */
interface PendingNickProtectionRegistryInterface
{
    public function schedule(string $uid, DateTimeImmutable $now, float $delaySeconds = 0.5): void;

    public function cancel(string $uid): void;

    public function has(string $uid): bool;

    /**
     * Returns UIDs whose grace period has expired, removing them from the registry.
     *
     * @return string[]
     */
    public function flushExpired(DateTimeImmutable $now): array;

    public function clear(): void;
}
