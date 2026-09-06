<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Service;

use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Application\Service\BurstState;
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
