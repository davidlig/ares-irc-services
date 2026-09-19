<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\InMemory;

use App\NickServ\Adapter\Out\InMemory\InMemoryRegistrationThrottle;
use App\NickServ\Adapter\Out\InMemory\RegisterThrottleRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryRegistrationThrottle::class)]
final class InMemoryRegistrationThrottleTest extends TestCase
{
    #[Test]
    public function delegatesUsingTheExplicitTime(): void
    {
        $adapter = new InMemoryRegistrationThrottle(new RegisterThrottleRegistry());
        $now = new DateTimeImmutable('2026-09-06 12:00:00 UTC');

        self::assertSame(0, $adapter->remainingCooldownSeconds('ip:client', 300, $now));
        $adapter->recordAttempt('ip:client', $now);
        self::assertSame(240, $adapter->remainingCooldownSeconds('ip:client', 300, $now->modify('+60 seconds')));
    }
}
