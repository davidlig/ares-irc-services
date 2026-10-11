<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbOfflineTakeover;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(UdbOfflineTakeover::class)]
final class UdbOfflineTakeoverTest extends TestCase
{
    #[Test]
    public function legacyTextSnapshotImportFailsClosedAndRequiresNativeWireBootstrap(): void
    {
        try {
            new UdbOfflineTakeover()->takeover('/path/not/read');
            self::fail('Offline text snapshot import must be unsupported.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Offline text snapshots are unsupported; use the authorized native UDB wire takeover.',
                $exception->getMessage(),
            );
        }
    }
}
