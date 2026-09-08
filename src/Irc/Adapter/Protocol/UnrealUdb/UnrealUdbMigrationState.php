<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\PasswordMigrationStateInterface;

final class UnrealUdbMigrationState implements PasswordMigrationStateInterface
{
    /** @var array<string, true> */
    private array $migratedNicks = [];

    public function isMigrated(string $nickname): bool
    {
        return isset($this->migratedNicks[strtolower($nickname)]);
    }

    public function markAsMigrated(string $nickname): void
    {
        $this->migratedNicks[strtolower($nickname)] = true;
    }

    public function clear(): void
    {
        $this->migratedNicks = [];
    }
}
