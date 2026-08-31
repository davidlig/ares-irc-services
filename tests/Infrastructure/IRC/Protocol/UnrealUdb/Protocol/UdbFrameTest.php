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
        self::assertNull($frame->subcommand);
        self::assertNull($frame->errorCode);
    }

    #[Test]
    public function frameKindsMapToTheirWireSubcommands(): void
    {
        self::assertSame('HEL', UdbFrameKind::Hel->value);
        self::assertSame('HEL_ACK', UdbFrameKind::HelAck->value);
        self::assertSame('INF', UdbFrameKind::Inf->value);
        self::assertSame('RES', UdbFrameKind::Res->value);
        self::assertSame('BEGIN', UdbFrameKind::Begin->value);
        self::assertSame('PUT', UdbFrameKind::Put->value);
        self::assertSame('END', UdbFrameKind::End->value);
        self::assertSame('ACK', UdbFrameKind::Ack->value);
        self::assertSame('ERR', UdbFrameKind::Err->value);
        self::assertSame('INS', UdbFrameKind::Ins->value);
        self::assertSame('DEL', UdbFrameKind::Del->value);
        self::assertSame('DRP', UdbFrameKind::Drp->value);
        self::assertSame('OPT', UdbFrameKind::Opt->value);
    }
}
