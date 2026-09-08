<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbMigrationState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealUdbMigrationState::class)]
final class UnrealUdbMigrationStateTest extends TestCase
{
    #[Test]
    public function isMigratedReturnsFalseInitially(): void
    {
        $state = new UnrealUdbMigrationState();
        $this->assertFalse($state->isMigrated('User'));
    }

    #[Test]
    public function markAsMigratedSetsStateToTrue(): void
    {
        $state = new UnrealUdbMigrationState();
        $this->assertFalse($state->isMigrated('User'));
        $state->markAsMigrated('User');
        $this->assertTrue($state->isMigrated('User'));
    }

    #[Test]
    public function isMigratedIsCaseInsensitive(): void
    {
        $state = new UnrealUdbMigrationState();
        $state->markAsMigrated('User');
        $this->assertTrue($state->isMigrated('user'));
        $this->assertTrue($state->isMigrated('USER'));
    }

    #[Test]
    public function clearEmptiesState(): void
    {
        $state = new UnrealUdbMigrationState();
        $state->markAsMigrated('User');
        $state->clear();
        $this->assertFalse($state->isMigrated('User'));
    }
}
