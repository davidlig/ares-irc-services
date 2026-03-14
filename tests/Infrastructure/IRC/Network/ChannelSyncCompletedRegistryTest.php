<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network;

use App\Infrastructure\IRC\Network\ChannelSyncCompletedRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelSyncCompletedRegistry::class)]
final class ChannelSyncCompletedRegistryTest extends TestCase
{
    private ChannelSyncCompletedRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ChannelSyncCompletedRegistry();
    }

    #[Test]
    public function markSyncCompletedMarksChannel(): void
    {
        $this->registry->markSyncCompleted('#Test');

        self::assertTrue($this->registry->isSyncCompleted('#test'));
    }

    #[Test]
    public function isSyncCompletedReturnsFalseWhenNotMarked(): void
    {
        self::assertFalse($this->registry->isSyncCompleted('#nonexistent'));
    }

    #[Test]
    public function isSyncCompletedNormalizesToLowercase(): void
    {
        $this->registry->markSyncCompleted('#TEST');

        self::assertTrue($this->registry->isSyncCompleted('#test'));
        self::assertTrue($this->registry->isSyncCompleted('#TEST'));
    }

    #[Test]
    public function getSyncCompletedAtReturnsTimestampWhenMarked(): void
    {
        $before = microtime(true);
        $this->registry->markSyncCompleted('#channel');
        $after = microtime(true);

        $timestamp = $this->registry->getSyncCompletedAt('#channel');

        self::assertNotNull($timestamp);
        self::assertGreaterThanOrEqual($before, $timestamp);
        self::assertLessThanOrEqual($after, $timestamp);
    }

    #[Test]
    public function getSyncCompletedAtReturnsNullWhenNotMarked(): void
    {
        self::assertNull($this->registry->getSyncCompletedAt('#nonexistent'));
    }

    #[Test]
    public function multipleChannelsTrackedIndependently(): void
    {
        $this->registry->markSyncCompleted('#alpha');
        $this->registry->markSyncCompleted('#beta');

        self::assertTrue($this->registry->isSyncCompleted('#alpha'));
        self::assertTrue($this->registry->isSyncCompleted('#beta'));
        self::assertFalse($this->registry->isSyncCompleted('#gamma'));
    }
}
