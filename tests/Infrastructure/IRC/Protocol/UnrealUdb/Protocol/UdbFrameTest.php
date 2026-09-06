<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Protocol;

use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrame;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrameKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbFrame::class)]
#[CoversClass(UdbFrameKind::class)]
final class UdbFrameTest extends TestCase
{
    #[Test]
    public function frameCarriesItsFields(): void
    {
        $frame = new UdbFrame(
            UdbFrameKind::Put,
            '001',
            '002',
            roundId: 10,
            block: UdbBlock::Ips,
            txid: 'tx1',
            checksum: 'ABCDEF12',
            path: 'a::b',
            value: 'v',
            epoch: '0123456789abcdef',
            capabilities: ['OCL', 'OCLG'],
            status: 'READY',
            count: 1,
        );

        self::assertSame(UdbFrameKind::Put, $frame->kind);
        self::assertSame('001', $frame->sourceSid);
        self::assertSame('002', $frame->target);
        self::assertSame(10, $frame->roundId);
        self::assertSame('tx1', $frame->txid);
        self::assertSame('ABCDEF12', $frame->checksum);
        self::assertSame('a::b', $frame->path);
        self::assertSame('v', $frame->value);
        self::assertNull($frame->propagator);
        self::assertSame('0123456789abcdef', $frame->epoch);
        self::assertSame(['OCL', 'OCLG'], $frame->capabilities);
        self::assertNull($frame->subcommand);
        self::assertNull($frame->errorCode);
        self::assertSame('READY', $frame->status);
        self::assertSame(1, $frame->count);
    }

    #[Test]
    public function frameKindsMapToTheirWireSubcommands(): void
    {
        $actual = array_map(static fn (UdbFrameKind $kind): string => $kind->value, UdbFrameKind::cases());

        self::assertContains('HEL', $actual);
        self::assertContains('PUT', $actual);
        self::assertContains('OCLG_END', $actual);
    }
}
