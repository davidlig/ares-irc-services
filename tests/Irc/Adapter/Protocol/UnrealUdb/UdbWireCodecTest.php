<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrameKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbOclgViewDigest;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbWireCodec;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbWireCodec::class)]
#[CoversClass(UdbOclgViewDigest::class)]
final class UdbWireCodecTest extends TestCase
{
    private const string DIGEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[Test]
    public function nativeTxidLengthBoundaryIsPreservedAcrossBeginPutEndAndAck(): void
    {
        $txid = str_repeat('t', 31);
        foreach ([
            UdbWireCodec::begin('001', '002', 1, UdbBlock::Settings, $txid, self::DIGEST, 0),
            UdbWireCodec::put('001', '002', 1, UdbBlock::Settings, $txid, 'quit_ips', UdbValue::string("fixture\t\xff")),
            UdbWireCodec::end('001', '002', 1, UdbBlock::Settings, $txid, self::DIGEST, 0),
            UdbWireCodec::ack('001', '002', 1, UdbBlock::Settings, $txid, self::DIGEST, 0),
        ] as $line) {
            $frame = $this->parse($line);
            self::assertSame($txid, $frame->txid);
            if (UdbFrameKind::Put === $frame->kind) {
                self::assertSame("fixture\t\xff", $frame->typedValue?->value);
            }
        }
        $invalid = IRCMessage::fromRawLine(':001 DB 002 BEGIN 1 S ' . $txid . 't ' . self::DIGEST . ' 0');
        self::assertNull(UdbWireCodec::parse($invalid));
    }

    #[Test]
    public function buildersRenderTheCurrentGrammar(): void
    {
        self::assertSame(':002 DB 001 HEL 4 irc.example.net 0123456789abcdef OCL OCLG', UdbWireCodec::hel('002', '001', 'irc.example.net', '0123456789abcdef', ['OCL', 'OCLG']));
        self::assertSame(':002 DB 001 HEL 4 ACK irc.example.net 0123456789abcdef OCL', UdbWireCodec::helAck('002', '001', 'irc.example.net', '0123456789abcdef'));
        self::assertSame(':002 DB 001 INF 1000 N ' . self::DIGEST . ' 3 0 42 001 0123456789abcdef', UdbWireCodec::inf('002', '001', 1000, UdbBlock::Nicks, self::DIGEST, 3, 42, '001', '0123456789abcdef'));
        self::assertSame(':002 DB 001 RES 1000 C', UdbWireCodec::res('002', '001', 1000, UdbBlock::Channels));
        self::assertSame(':002 DB 001 BEGIN 1000 I ab12 ' . self::DIGEST . ' 42', UdbWireCodec::begin('002', '001', 1000, UdbBlock::Ips, 'ab12', self::DIGEST, 42));
        self::assertSame(':002 DB 001 PUT 1000 I ab12 I::path :!u5', UdbWireCodec::put('002', '001', 1000, UdbBlock::Ips, 'ab12', 'path', UdbValue::number(UdbUnsignedDecimal::fromInt(5))));
        self::assertSame(':002 DB 001 END 1000 I ab12 ' . self::DIGEST . ' 42', UdbWireCodec::end('002', '001', 1000, UdbBlock::Ips, 'ab12', self::DIGEST, 42));
        self::assertSame(':002 DB 001 ACK 1000 I ab12 ' . self::DIGEST . ' 42', UdbWireCodec::ack('002', '001', 1000, UdbBlock::Ips, 'ab12', self::DIGEST, 42));
        self::assertSame(':002 DB 001 ERR PUT 3 1000 C', UdbWireCodec::err('002', '001', 'PUT', 3, 1000, UdbBlock::Channels));
        self::assertSame(':002 DB 001 ERR INS 6 7 0', UdbWireCodec::err('002', '001', 'INS', 6, 7, null));
        self::assertSame(':002 DB * INS 0123456789abcdef 1 C::path :!sYWxpY2U=', UdbWireCodec::ins('002', '0123456789abcdef', 1, 'C', 'path', UdbValue::string('alice')));
        self::assertSame(':002 DB * DEL 0123456789abcdef 2 C::path', UdbWireCodec::del('002', '0123456789abcdef', 2, 'C', 'path'));
        self::assertSame(':002 DB * DRP 0123456789abcdef 3 C', UdbWireCodec::drp('002', '0123456789abcdef', 3, UdbBlock::Channels));
        self::assertSame(':002 DB 001 EXP K::G::*@host 1700000000', UdbWireCodec::exp('002', '001', 'K::G::*@host', 1700000000));
        self::assertSame(':002 DB 001 MANIFEST REQ 9', UdbWireCodec::manifestReq('002', '001', 9));
        self::assertSame(':002 DB 001 MANIFEST ACK 9 K 4 ' . self::DIGEST . ' 42', UdbWireCodec::manifestAck('002', '001', 9, UdbBlock::Lines, 4, self::DIGEST, 42));
    }

    #[Test]
    public function invalidDigestIsRejectedWithoutSubstitution(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UdbWireCodec::begin('002', '001', 1, UdbBlock::Nicks, 'tx', 'INVALID', 0);
    }

    #[Test]
    public function parsesInventoryAndStagedFramesWithDurableWatermarks(): void
    {
        $inf = $this->parse(':001 DB 002 INF 999 C ' . self::DIGEST . ' 7 0 18446744073709551615 001 0123456789abcdef');
        self::assertSame(UdbFrameKind::Inf, $inf->kind);
        self::assertSame(7, $inf->count);
        self::assertSame('18446744073709551615', (string) $inf->watermark);
        self::assertSame(0, $inf->timestamp);
        self::assertSame('001', $inf->rootSid);
        self::assertSame('0123456789abcdef', $inf->rootEpoch);

        $begin = $this->parse(':001 DB 002 BEGIN 999 S tx_1 ' . self::DIGEST . ' 12');
        self::assertSame(UdbFrameKind::Begin, $begin->kind);
        self::assertSame('12', (string) $begin->watermark);
        self::assertSame('tx_1', $begin->txid);

        $put = $this->parse(':001 DB 002 PUT 999 S tx_1 S::nickserv :!sTmlja1NlcnYgdmFsdWU=');
        self::assertSame(UdbFrameKind::Put, $put->kind);
        self::assertSame('!sTmlja1NlcnYgdmFsdWU=', $put->value);

        foreach (['END' => UdbFrameKind::End, 'ACK' => UdbFrameKind::Ack] as $verb => $kind) {
            $frame = $this->parse(':001 DB 002 ' . $verb . ' 999 S tx_1 ' . self::DIGEST . ' 0');
            self::assertSame($kind, $frame->kind);
            self::assertSame('0', (string) $frame->watermark);
        }
        self::assertSame(UdbFrameKind::Res, $this->parse(':001 DB 002 RES 999 K')->kind);
    }

    #[Test]
    public function parsesSequencedMutationsExpiryAndManifest(): void
    {
        $ins = $this->parse(':001 DB * INS 0123456789abcdef 1 S::nickserv :!sbWFzayB2YWx1ZQ==');
        self::assertSame(UdbFrameKind::Ins, $ins->kind);
        self::assertSame('1', (string) $ins->sequence);
        self::assertSame('0123456789abcdef', $ins->epoch);
        self::assertSame('!sbWFzayB2YWx1ZQ==', $ins->value);

        self::assertSame(UdbFrameKind::Del, $this->parse(':001 DB * DEL 0123456789abcdef 2 S::nickserv')->kind);
        self::assertSame(UdbFrameKind::Drp, $this->parse(':001 DB * DRP 0123456789abcdef 3 I')->kind);
        $exp = $this->parse(':001 DB 002 EXP K::G::*@host 1700000000');
        self::assertSame(UdbFrameKind::Exp, $exp->kind);
        self::assertSame(1700000000, $exp->expectedExpires);

        self::assertSame(UdbFrameKind::ManifestReq, $this->parse(':001 DB 002 MANIFEST REQ 9')->kind);
        $ack = $this->parse(':001 DB 002 MANIFEST ACK 9 K 4 ' . self::DIGEST . ' 42');
        self::assertSame(UdbFrameKind::ManifestAck, $ack->kind);
        self::assertSame(4, $ack->count);
        self::assertSame('42', (string) $ack->watermark);
    }

    #[Test]
    public function parsesHelloErrorsAndOclg(): void
    {
        $hel = $this->parse(':001 DB 002 HEL 4 ? 0123456789abcdef OCL OCLG');
        self::assertSame(UdbFrameKind::Hel, $hel->kind);
        self::assertSame(['OCL', 'OCLG'], $hel->capabilities);
        self::assertSame(UdbFrameKind::HelAck, $this->parse(':001 DB 002 HEL 4 ACK irc.example 0123456789abcdef OCL')->kind);

        $err = $this->parse(':001 DB 002 ERR MANIFEST 3 999 0');
        self::assertSame(UdbFrameKind::Err, $err->kind);
        self::assertNull($err->block);

        $begin = $this->parse(':001 DB 002 OCLG BEGIN 0123456789abcdef 7 READY 1 ' . self::DIGEST);
        self::assertSame(UdbFrameKind::OclgBegin, $begin->kind);
        self::assertSame(UdbFrameKind::OclgItem, $this->parse(':001 DB 002 OCLG ITEM 0123456789abcdef 7 netadmin ' . self::DIGEST)->kind);
        self::assertSame(UdbFrameKind::OclgEnd, $this->parse(':001 DB 002 OCLG END 0123456789abcdef 7')->kind);
    }

    #[Test]
    public function oclgViewDigestIsCanonicalAndIncludesReadiness(): void
    {
        $unsorted = UdbOclgViewDigest::fromEntries(true, [
            'netadmin' => str_repeat('b', 64),
            'admin' => str_repeat('a', 64),
        ]);
        $sorted = UdbOclgViewDigest::fromEntries(true, [
            'admin' => str_repeat('a', 64),
            'netadmin' => str_repeat('b', 64),
        ]);

        self::assertSame($sorted, $unsorted);
        self::assertNotSame($sorted, UdbOclgViewDigest::fromEntries(false, [
            'admin' => str_repeat('a', 64),
            'netadmin' => str_repeat('b', 64),
        ]));
        self::assertTrue(UdbOclgViewDigest::isValid($sorted));
    }

    #[Test]
    public function rejectsLegacyAndMalformedFrames(): void
    {
        $malformed = [
            'PRIVMSG #chan :hello', ':001 DB', ':001 DB 002 OPT S',
            ':001 DB 002 HEL 5 x', ':001 DB 002 HEL 4', ':001 DB 002 HEL 4 x 0123456789ABCDEf OCL', ':001 DB 002 HEL 4 x 0123456789abcdef OCL EXTRA',
            ':001 DB 002 INF 1 N ' . self::DIGEST . ' 0',
            ':001 DB 002 INF 0 N ' . self::DIGEST . ' 0 1', ':001 DB 002 INF 1 X ' . self::DIGEST . ' 0 1', ':001 DB 002 INF 1 N ' . strtoupper(self::DIGEST) . ' 0 1', ':001 DB 002 INF 1 N ' . self::DIGEST . ' x 1', ':001 DB 002 INF 1 N ' . self::DIGEST . ' 0 1 bad',
            ':001 DB 002 INF 1 N ' . self::DIGEST . ' 0 1700000000 0 001 0123456789abcdef',
            ':001 DB 002 RES 0 N', ':001 DB 002 RES 1 N extra', ':001 DB 002 BEGIN 1 N bad.txid ' . self::DIGEST, ':001 DB 002 BEGIN 1 N tx short',
            ':001 DB 002 PUT 1 N tx', ':001 DB 002 PUT 1 N tx N::bad%41path :v', ':001 DB 002 PUT 1 N tx N::path', ':001 DB 002 PUT 1 N tx N::path :',
            ':001 DB 002 PUT 1 N tx N::nick::pass :legacy-raw-value',
            ':001 DB 002 END 1 N tx', ':001 DB 002 ACK 1 N tx ' . self::DIGEST . ' bad',
            ':001 DB 002 ERR PUT 3 1', ':001 DB 002 ERR PUT 256 1 C', ':001 DB 002 ERR PUT 3 1 X',
            ':001 DB * INS S::nickserv :legacy', ':001 DB * INS BAD 1 S::nickserv :v', ':001 DB * INS 0123456789abcdef 0 S::nickserv :v',
            ':001 DB * INS 0123456789abcdef 1 NN::nick :v', ':001 DB * DEL 0123456789abcdef 1', ':001 DB * DEL 0123456789abcdef 1 S::%41', ':001 DB * DRP 0123456789abcdef 1', ':001 DB * DRP 0123456789abcdef 1 X',
            ':001 DB 002 EXP K::G::*@host', ':001 DB 002 EXP N::nick::pass 1', ':001 DB 002 EXP K::G::*@host 0',
            ':001 DB 002 MANIFEST REQ 0', ':001 DB 002 MANIFEST ACK 1 K', ':001 DB 002 MANIFEST ACK 1 X 0 ' . self::DIGEST . ' 0', ':001 DB 002 MANIFEST ACK 1 K x ' . self::DIGEST . ' 0',
            ':001 DB 002 OCLG BEGIN bad 7 READY 1 ' . self::DIGEST, ':001 DB 002 OCLG BEGIN 0123456789abcdef 7 BAD 1 ' . self::DIGEST,
            ':001 DB 002 OCLG ITEM 0123456789abcdef 7 netadmin short', ':001 DB 002 OCLG FOO 0123456789abcdef 7',
        ];
        foreach ($malformed as $raw) {
            self::assertNull(UdbWireCodec::parse(IRCMessage::fromRawLine($raw)), $raw);
        }
    }

    private function parse(string $raw): UdbFrame
    {
        $frame = UdbWireCodec::parse(IRCMessage::fromRawLine($raw));
        self::assertNotNull($frame, $raw);

        return $frame;
    }
}
