<?php

declare(strict_types=1);

namespace App\Tests\Irc\Application\Port\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\RunMaintenanceCycle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunMaintenanceCycle::class)]
final class RunMaintenanceCycleTest extends TestCase
{
    #[Test]
    public function messageCanBeInstantiated(): void
    {
        $message = new RunMaintenanceCycle();

        self::assertSame([], get_object_vars($message));
    }
}
