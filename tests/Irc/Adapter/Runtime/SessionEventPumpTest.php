<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Runtime;

use App\Irc\Adapter\Runtime\SessionEventPump;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionEventPump::class)]
final class SessionEventPumpTest extends TestCase
{
    private SessionEventPump $pump;

    protected function setUp(): void
    {
        $this->pump = new SessionEventPump();
    }

    #[Test]
    public function initialState(): void
    {
        $this->pump->awaitReaderCapacity();

        self::assertFalse($this->pump->isRunning());
        self::assertFalse($this->pump->isStopped());
        self::assertSame(0, $this->pump->getQueueSize());
        self::assertSame(0, $this->pump->getPeakQueueSize());
    }

    #[Test]
    public function tasksExecuteStrictlyInFifoOrder(): void
    {
        $log = [];

        $this->pump->enqueue(static function () use (&$log): void {
            $log[] = 'task 1';
        });
        $this->pump->enqueue(static function () use (&$log): void {
            $log[] = 'task 2';
        });
        $this->pump->enqueue(function () use (&$log): void {
            $log[] = 'task 3';
            $this->pump->stop();
        });

        self::assertSame(3, $this->pump->getQueueSize());

        $this->pump->drain();

        self::assertSame(['task 1', 'task 2', 'task 3'], $log);
        self::assertTrue($this->pump->isStopped());
        self::assertFalse($this->pump->isRunning());
    }

    #[Test]
    public function taskSuspensionPreventsReentrantExecutionOfLaterTasks(): void
    {
        $log = [];

        // Task 1 suspends (simulating backpressure during socket write)
        $this->pump->enqueue(static function () use (&$log): void {
            $log[] = 'task 1 start';
            \Amp\delay(0.04);
            $log[] = 'task 1 end';
        });

        // Enqueue Task 2 while Task 1 is about to run or running
        \Amp\async(function () use (&$log): void {
            \Amp\delay(0.01); // while task 1 is in progress
            $log[] = 'producer: enqueue task 2';
            $this->pump->enqueue(function () use (&$log): void {
                $log[] = 'task 2 executed';
                $this->pump->stop();
            });
        });

        $this->pump->drain();

        self::assertSame([
            'task 1 start',
            'producer: enqueue task 2',
            'task 1 end',
            'task 2 executed',
        ], $log);
    }

    #[Test]
    public function drainThrowsIfAlreadyRunning(): void
    {
        $caught = false;
        $this->pump->enqueue(function () use (&$caught): void {
            try {
                $this->pump->drain();
            } catch (LogicException $e) {
                $caught = true;
                self::assertSame('SessionEventPump is already running.', $e->getMessage());
            } finally {
                $this->pump->stop();
            }
        });

        $this->pump->drain();

        self::assertTrue($caught);
    }

    #[Test]
    public function enqueueIgnoredWhenStopped(): void
    {
        $this->pump->stop();
        self::assertTrue($this->pump->isStopped());

        $this->pump->enqueue(static function (): void {
            self::fail('Should not be executed');
        });

        self::assertSame(0, $this->pump->getQueueSize());
    }

    #[Test]
    public function drainAwaitsWhenQueueEmptyAndResumesOnEnqueue(): void
    {
        $executed = false;

        \Amp\async(function () use (&$executed): void {
            \Amp\delay(0.01);
            $this->pump->enqueue(function () use (&$executed): void {
                $executed = true;
                $this->pump->stop();
            });
        });

        $this->pump->drain();

        self::assertTrue($executed);
    }

    #[Test]
    public function drainAwaitsWhenQueueEmptyAndStopsCleanlyOnStop(): void
    {
        \Amp\async(function (): void {
            \Amp\delay(0.01);
            $this->pump->stop();
        });

        $this->pump->drain();

        self::assertTrue($this->pump->isStopped());
        self::assertFalse($this->pump->isRunning());
    }

    #[Test]
    public function coalescedTasksRunLatestCallbackAtTheirOriginalFifoPosition(): void
    {
        $events = [];
        $this->pump->enqueue(static function () use (&$events): void {
            $events[] = 'before';
        });
        $this->pump->enqueueCoalesced('deadline', static function () use (&$events): void {
            $events[] = 'stale';
        });
        $this->pump->enqueue(static function () use (&$events): void {
            $events[] = 'after';
        });
        $this->pump->enqueueCoalesced('deadline', static function () use (&$events): void {
            $events[] = 'latest';
        });
        $this->pump->enqueueCoalesced('maintenance', function () use (&$events): void {
            $events[] = 'maintenance';
            $this->pump->stop();
        });

        self::assertSame(4, $this->pump->getQueueSize());
        $this->pump->drain();
        self::assertSame(['before', 'latest', 'after', 'maintenance'], $events);
        self::assertSame(4, $this->pump->getPeakQueueSize());
    }

    #[Test]
    public function readerWaitsAtHighWaterAndResumesAtLowWater(): void
    {
        for ($i = 0; $i < SessionEventPump::READER_HIGH_WATER; ++$i) {
            $this->pump->enqueue(static function (): void {});
        }

        $resumed = false;
        $reader = \Amp\async(function () use (&$resumed): void {
            $this->pump->awaitReaderCapacity();
            $resumed = !$this->pump->isStopped();
            $this->pump->stop();
        });
        \Amp\delay(0);
        $this->pump->drain();
        $reader->await();

        self::assertTrue($resumed);
        self::assertSame(SessionEventPump::READER_HIGH_WATER, $this->pump->getPeakQueueSize());
    }

    #[Test]
    public function stopWakesReaderAndDropsQueuedClosures(): void
    {
        for ($i = 0; $i < SessionEventPump::READER_HIGH_WATER; ++$i) {
            $this->pump->enqueue(static function (): void {});
        }

        $resumed = null;
        $reader = \Amp\async(function () use (&$resumed): void {
            $this->pump->awaitReaderCapacity();
            $resumed = !$this->pump->isStopped();
        });
        \Amp\delay(0.001);
        $this->pump->stop();
        $reader->await();

        self::assertFalse($resumed);
        $this->pump->awaitReaderCapacity();
        self::assertTrue($this->pump->isStopped());
        self::assertSame(0, $this->pump->getQueueSize());
        $this->pump->enqueueCoalesced('maintenance', static function (): void {});
        self::assertSame(0, $this->pump->getQueueSize());
    }

    #[Test]
    public function readerHighWaterKeepsReserveForNonReaderProducers(): void
    {
        for ($i = 0; $i < SessionEventPump::READER_HIGH_WATER; ++$i) {
            $this->pump->enqueue(static function (): void {});
        }

        $this->pump->enqueue(static function (): void {});
        $this->pump->enqueueCoalesced('maintenance', static function (): void {});
        $this->pump->enqueueCoalesced('udb-mutation-flush', static function (): void {});
        $this->pump->enqueue(static function (): void {});

        self::assertSame(SessionEventPump::READER_HIGH_WATER + 4, $this->pump->getQueueSize());
    }

    #[Test]
    public function queueHasAHardCapacity(): void
    {
        for ($i = 0; $i < SessionEventPump::MAX_QUEUE_SIZE; ++$i) {
            $this->pump->enqueue(static function (): void {});
        }
        self::assertSame(SessionEventPump::MAX_QUEUE_SIZE, $this->pump->getPeakQueueSize());

        $this->expectException(LogicException::class);
        $this->pump->enqueue(static function (): void {});
    }

    #[Test]
    public function failedCoalescedEnqueueClearsItsKeySoItCanBeRetried(): void
    {
        $executed = false;
        $this->pump->enqueue(function () use (&$executed): void {
            $this->pump->enqueueCoalesced('overflow', function () use (&$executed): void {
                $executed = true;
                $this->pump->stop();
            });
        });
        for ($i = 1; $i < SessionEventPump::MAX_QUEUE_SIZE; ++$i) {
            $this->pump->enqueue(static function (): void {});
        }

        try {
            $this->pump->enqueueCoalesced('overflow', static function (): void {});
            self::fail('A coalesced task cannot exceed the hard queue capacity.');
        } catch (LogicException $e) {
            self::assertSame('SessionEventPump queue capacity exceeded.', $e->getMessage());
        }

        $this->pump->drain();

        self::assertTrue($executed);
    }
}
