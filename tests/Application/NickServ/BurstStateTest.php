<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\BurstState;
use App\Application\Port\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BurstState::class)]
final class BurstStateTest extends TestCase
{
    #[Test]
    public function isCompleteReturnsFalseInitially(): void
    {
        $state = new BurstState();
        self::assertFalse($state->isComplete());
    }

    #[Test]
    public function markCompleteSetsComplete(): void
    {
        $state = new BurstState();
        $state->markComplete();
        self::assertTrue($state->isComplete());
    }

    #[Test]
    public function addPendingAndTakePendingReturnsAndClears(): void
    {
        $state = new BurstState();
        $view = new SenderView('001A', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'host');
        $state->addPending($view);
        $pending = $state->takePending();
        self::assertCount(1, $pending);
        self::assertSame($view, $pending[0]);
        self::assertCount(0, $state->takePending());
    }
}
