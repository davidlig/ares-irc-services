<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ;

use App\Infrastructure\ChanServ\ChannelRankSyncPendingRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelRankSyncPendingRegistry::class)]
final class ChannelRankSyncPendingRegistryTest extends TestCase
{
    private ChannelRankSyncPendingRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ChannelRankSyncPendingRegistry();
    }

    #[Test]
    public function addAddsChannelName(): void
    {
        $this->registry->add('#Test');
        $this->registry->snapshotPendingAtStart();

        self::assertSame(['#test'], $this->registry->getPendingAtStart());
    }

    #[Test]
    public function addNormalizesToLowercase(): void
    {
        $this->registry->add('#TEST');
        $this->registry->add('#Test');
        $this->registry->add('#test');
        $this->registry->snapshotPendingAtStart();

        self::assertSame(['#test'], $this->registry->getPendingAtStart());
    }

    #[Test]
    public function removeRemovesChannel(): void
    {
        $this->registry->add('#alpha');
        $this->registry->add('#beta');
        $this->registry->snapshotPendingAtStart();

        $this->registry->remove('#ALPHA');

        $pending = $this->registry->getPendingAtStart();
        self::assertNotContains('#alpha', $pending);
        self::assertContains('#beta', $pending);
    }

    #[Test]
    public function snapshotPendingAtStartCapturesCurrentState(): void
    {
        $this->registry->add('#first');
        $this->registry->snapshotPendingAtStart();
        $this->registry->add('#second');

        $pending = $this->registry->getPendingAtStart();

        self::assertContains('#first', $pending);
        self::assertNotContains('#second', $pending);
    }

    #[Test]
    public function getPendingAtStartReturnsEmptyArrayWhenNoSnapshot(): void
    {
        self::assertSame([], $this->registry->getPendingAtStart());
    }

    #[Test]
    public function addChannelsAddsFromEntityCollection(): void
    {
        $channel1 = new class {
            public function getName(): string
            {
                return '#channel1';
            }
        };

        $channel2 = new class {
            public function getName(): string
            {
                return '#CHANNEL2';
            }
        };

        $this->registry->addChannels([$channel1, $channel2]);
        $this->registry->snapshotPendingAtStart();

        $pending = $this->registry->getPendingAtStart();

        self::assertContains('#channel1', $pending);
        self::assertContains('#channel2', $pending);
    }

    #[Test]
    public function removeAlsoRemovesFromSnapshot(): void
    {
        $this->registry->add('#test');
        $this->registry->snapshotPendingAtStart();
        $this->registry->remove('#test');

        self::assertNotContains('#test', $this->registry->getPendingAtStart());
    }

    #[Test]
    public function multipleSnapshotsReplacePrevious(): void
    {
        $this->registry->add('#first');
        $this->registry->snapshotPendingAtStart();
        $this->registry->add('#second');
        $this->registry->snapshotPendingAtStart();

        $pending = $this->registry->getPendingAtStart();

        self::assertContains('#first', $pending);
        self::assertContains('#second', $pending);
    }

    #[Test]
    public function addAccumulatesChannels(): void
    {
        $this->registry->add('#alpha');
        $this->registry->add('#beta');
        $this->registry->snapshotPendingAtStart();

        $pending = $this->registry->getPendingAtStart();

        self::assertCount(2, $pending);
        self::assertContains('#alpha', $pending);
        self::assertContains('#beta', $pending);
    }
}
