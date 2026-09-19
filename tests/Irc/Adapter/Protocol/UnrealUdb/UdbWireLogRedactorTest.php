<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrameKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbWireCodec;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbWireLogRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(UdbWireLogRedactor::class)]
final class UdbWireLogRedactorTest extends TestCase
{
    #[Test]
    public function redactsSecretPutAndInsValues(): void
    {
        self::assertSame(
            ':002 DB 001 PUT 1000 N ab12 alice::pass :<redacted>',
            UdbWireLogRedactor::redact(UdbWireCodec::put('002', '001', 1000, UdbBlock::Nicks, 'ab12', 'alice::pass', 'crypt:$2y$10$hash value with spaces')),
        );
        self::assertSame(
            ':002 DB * INS 0123456789abcdef 1 N::alice::pass :<redacted>',
            UdbWireLogRedactor::redact(UdbWireCodec::ins('002', '0123456789abcdef', 1, 'N', 'alice::pass', 'crypt:$2y$10$hash')),
        );
        self::assertSame(
            ':002 DB * INS 0123456789abcdef 2 S::encryption_key :<redacted>',
            UdbWireLogRedactor::redact(UdbWireCodec::ins('002', '0123456789abcdef', 2, 'S', 'encryption_key', 'super-secret')),
        );
    }

    #[Test]
    public function redactsPercentEncodedAndCaseInsensitiveKeys(): void
    {
        self::assertSame(
            ':002 DB 001 PUT 1000 N ab12 alice%3Aadmin::pass :<redacted>',
            UdbWireLogRedactor::redact(UdbWireCodec::put('002', '001', 1000, UdbBlock::Nicks, 'ab12', 'alice%3Aadmin::pass', 'crypt:$2y$10$hash')),
        );
        self::assertSame(
            ':002 DB * INS 0123456789abcdef 3 N::alice::PASS :<redacted>',
            UdbWireLogRedactor::redact(UdbWireCodec::ins('002', '0123456789abcdef', 3, 'N', 'alice::PASS', 'crypt:$2y$10$hash')),
        );
    }

    #[Test]
    public function redactsColonlessSingleTokenValues(): void
    {
        self::assertSame(
            ':002 DB * INS 0123456789abcdef 4 N::alice::pass <redacted>',
            UdbWireLogRedactor::redact(':002 DB * INS 0123456789abcdef 4 N::alice::pass crypt:hash'),
        );
    }

    #[Test]
    public function logsNonSecretRecordValuesVerbatim(): void
    {
        $unchanged = [
            UdbWireCodec::put('002', '001', 1000, UdbBlock::Nicks, 'ab12', 'alice::vhost', 'a.example'),
            UdbWireCodec::put('002', '001', 1000, UdbBlock::Nicks, 'ab12', 'alice::access::carol', 'a.example'),
            UdbWireCodec::ins('002', '0123456789abcdef', 6, 'C', 'alice::vhost', 'a.example'),
            ':002 DB * INS 0123456789abcdef 7 N::alice::vhost a.example',
        ];

        foreach ($unchanged as $line) {
            self::assertSame($line, UdbWireLogRedactor::redact($line));
        }
    }

    #[Test]
    public function logsFramesWithoutRecordValuesVerbatim(): void
    {
        $digest = str_repeat('a', 64);
        $unchanged = [
            UdbWireCodec::hel('002', '001', 'services.example', '0123456789abcdef'),
            UdbWireCodec::helAck('002', '001', 'services.example', '0123456789abcdef'),
            UdbWireCodec::inf('002', '001', 1000, UdbBlock::Nicks, $digest, 1, 1700000000),
            UdbWireCodec::res('002', '001', 1000, UdbBlock::Nicks),
            UdbWireCodec::begin('002', '001', 1000, UdbBlock::Nicks, 'tx1', $digest),
            UdbWireCodec::end('002', '001', 1000, UdbBlock::Nicks, 'tx1', $digest),
            UdbWireCodec::ack('002', '001', 1000, UdbBlock::Nicks, 'tx1', $digest),
            UdbWireCodec::del('002', '0123456789abcdef', 5, 'N', 'alice::pass'),
            UdbWireCodec::exp('002', '001', 'K::G::*@host', 1700000000),
            UdbWireCodec::manifestReq('002', '001', 9),
            UdbWireCodec::manifestAck('002', '001', 9, UdbBlock::Lines, 4, $digest, 42),
            ':002 NOT_A_UDB_FRAME',
        ];

        foreach ($unchanged as $line) {
            self::assertSame($line, UdbWireLogRedactor::redact($line));
        }
    }

    #[Test]
    public function malformedLinesAreReturnedUntouched(): void
    {
        $unchanged = [
            ':002 DB 001 PUT 1000 N ab12 alice::pa%73s :crypt:hash',
            ':002 DB 001 PUT 1000 Z ab12 alice::pass :crypt:hash',
            ':002 DB 002 BOGUS',
        ];

        foreach ($unchanged as $line) {
            self::assertSame($line, UdbWireLogRedactor::redact($line));
        }
    }

    #[Test]
    public function masksValueBearingFramesThatCannotBeClassified(): void
    {
        self::assertSame(
            ':001 DB 002 HEL 4 services 0123456789abcdef OCL :<redacted>',
            UdbWireLogRedactor::redactFrame(new UdbFrame(UdbFrameKind::Hel, '001', '002', value: 'topsecret'), ':001 DB 002 HEL 4 services 0123456789abcdef OCL :topsecret'),
        );
        self::assertSame(
            ':002 DB 001 PUT 1000 N ab12 alice::pa%73s :<redacted>',
            UdbWireLogRedactor::redactFrame(new UdbFrame(UdbFrameKind::Put, '001', '002', block: UdbBlock::Nicks, path: 'alice::pa%73s', value: 'crypt:hash'), ':002 DB 001 PUT 1000 N ab12 alice::pa%73s :crypt:hash'),
        );
        self::assertSame(
            ':002 DB * INS 0123456789abcdef 8 Z::alice::pass :<redacted>',
            UdbWireLogRedactor::redactFrame(new UdbFrame(UdbFrameKind::Ins, '001', '002', path: 'Z::alice::pass', value: 'crypt:hash'), ':002 DB * INS 0123456789abcdef 8 Z::alice::pass :crypt:hash'),
        );
    }
}
