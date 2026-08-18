<?php

declare(strict_types=1);

namespace App\Application\Port;

interface PasswordMigrationStateInterface
{
    /**
     * Returns true if the nickname's password has been migrated to the IRCd.
     */
    public function isMigrated(string $nickname): bool;

    /**
     * Marks the nickname as migrated to the IRCd.
     */
    public function markAsMigrated(string $nickname): void;

    /**
     * Clears the migration state.
     */
    public function clear(): void;
}
