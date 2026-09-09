<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Out\Time;

use App\Irc\Adapter\Out\Time\SystemAntifloodClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function time;

#[CoversClass(SystemAntifloodClock::class)]
final class SystemAntifloodClockTest extends TestCase
{
    #[Test]
    public function returnsCurrentUnixTimestamp(): void
    {
        $before = time();
        $now = new SystemAntifloodClock()->now();
        $after = time();

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }
}
