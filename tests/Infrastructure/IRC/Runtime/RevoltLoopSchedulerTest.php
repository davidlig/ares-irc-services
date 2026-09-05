<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Runtime;

use App\Infrastructure\IRC\Runtime\RevoltLoopScheduler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RevoltLoopScheduler::class)]
final class RevoltLoopSchedulerTest extends TestCase
{
    private RevoltLoopScheduler $scheduler;

    protected function setUp(): void
    {
        $this->scheduler = new RevoltLoopScheduler();
    }

    #[Test]
    public function delaySchedulesCallbackAndCanBeCancelled(): void
    {
        $invoked = false;
        $watcherId = $this->scheduler->delay(0.05, static function () use (&$invoked): void {
            $invoked = true;
        });

        self::assertNotEmpty($watcherId);

        $this->scheduler->cancel($watcherId);

        // Wait a bit to ensure it does not fire
        \Amp\delay(0.08);

        self::assertFalse($invoked);
    }

    #[Test]
    public function delayExecutesCallbackWhenNotCancelled(): void
    {
        $invoked = false;
        $watcherId = $this->scheduler->delay(0.01, static function () use (&$invoked): void {
            $invoked = true;
        });

        self::assertNotEmpty($watcherId);

        \Amp\delay(0.03);

        self::assertTrue($invoked);
    }

    #[Test]
    public function repeatSchedulesRecurringCallbackAndCanBeCancelled(): void
    {
        $count = 0;
        $watcherId = $this->scheduler->repeat(0.01, static function () use (&$count): void {
            ++$count;
        });

        self::assertNotEmpty($watcherId);

        \Amp\delay(0.035);

        self::assertGreaterThanOrEqual(2, $count);

        $this->scheduler->cancel($watcherId);
        $countAtCancel = $count;

        \Amp\delay(0.03);

        self::assertSame($countAtCancel, $count);
    }
}
