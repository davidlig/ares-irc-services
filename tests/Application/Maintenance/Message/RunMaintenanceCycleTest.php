<?php

declare(strict_types=1);

namespace App\Tests\Application\Maintenance\Message;

use App\Application\Maintenance\Message\RunMaintenanceCycle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunMaintenanceCycle::class)]
final class RunMaintenanceCycleTest extends TestCase
{
    #[Test]
    public function canBeInstantiated(): void
    {
        $message = new RunMaintenanceCycle();

        self::assertInstanceOf(RunMaintenanceCycle::class, $message);
    }
}
