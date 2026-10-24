<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance\Pruner;

use App\NickServ\Adapter\In\Maintenance\Pruner\RescueSecurityPruner;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RescueAttemptLimiter;
use App\NickServ\Application\Port\Out\TemporaryRescueTokenStore;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RescueSecurityPruner::class)]
final class RescueSecurityPrunerTest extends TestCase
{
    #[Test]
    public function prunesBothBoundedStoresAtTheSameClockInstant(): void
    {
        $now = new DateTimeImmutable();
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        $tokens = $this->createMock(TemporaryRescueTokenStore::class);
        $tokens->expects(self::once())->method('prune')->with($now)->willReturn(2);
        $attempts = $this->createMock(RescueAttemptLimiter::class);
        $attempts->expects(self::once())->method('prune')->with($now)->willReturn(3);
        self::assertSame(5, new RescueSecurityPruner($tokens, $attempts, $clock)->prune());
    }
}
