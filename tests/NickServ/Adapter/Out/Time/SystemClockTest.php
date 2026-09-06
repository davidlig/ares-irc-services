<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Time;

use App\NickServ\Adapter\Out\Time\SystemClock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    #[Test]
    public function returnsCurrentImmutableTime(): void
    {
        $before = new DateTimeImmutable();
        $now = new SystemClock()->now();
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }
}
