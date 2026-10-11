<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbCapturedSnapshot;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbSnapshotProviderInterface;

/** Deterministic disk-spooled snapshot source for session state-machine tests. */
final class FakeUdbSnapshots implements UdbSnapshotProviderInterface
{
    /**
     * @param array<string, array<string, string>> $blocks
     */
    public function __construct(private array $blocks = [], private int $watermark = 0) {}

    public function setWatermark(int $watermark): void
    {
        $this->watermark = $watermark;
    }

    public function capture(): UdbCapturedSnapshot
    {
        return UdbTestValues::snapshot($this->blocks, $this->watermark);
    }

    /**
     * @param array<string, string> $records
     */
    public function setBlock(UdbBlock $block, array $records): void
    {
        $this->blocks[$block->letter()] = $records;
    }
}
