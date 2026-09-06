<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Protocol;

use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrameKind;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbOclgViewDigest;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbWireCodec;
use App\Irc\Adapter\Protocol\IRCMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbWireCodec::class)]
#[CoversClass(UdbOclgViewDigest::class)]
final class UdbWireCodecTest extends TestCase
{
    #[Test]
    public function buildersRenderExactWireGrammar(): void
    {
        self::assertSame(':002 DB 001 HEL 4 irc.example.net 0123456789abcdef OCL OCLG', UdbWireCodec::hel('002', '001', 'irc.example.net', '0123456789abcdef', ['OCL', 'OCLG']));
        self::assertSame(':002 DB 001 HEL 4 ACK irc.example.net 0123456789abcdef OCL', UdbWireCodec::helAck('002', '001', 'irc.example.net', '0123456789abcdef'));
        self::assertSame(
            ':002 DB 001 INF 1000 N ABCDEF12 1700000000',
            UdbWireCodec::inf('002', '001', 1000, UdbBlock::Nicks, 'ABCDEF12', 1700000000),
        );
        self::assertSame(':002 DB 001 RES 1000 C', UdbWireCodec::res('002', '001', 1000, UdbBlock::Channels));
        self::assertSame(
            ':002 DB 001 BEGIN 1000 I ab12 0000000F',
            UdbWireCodec::begin('002', '001', 1000, UdbBlock::Ips, 'ab12', 'F'),
        );
        self::assertSame(
            ':002 DB 001 PUT 1000 I ab12 1.2.3.4%3A%3Aclones :*5',
            UdbWireCodec::put('002', '001', 1000, UdbBlock::Ips, 'ab12', '1.2.3.4%3A%3Aclones', '*5'),
        );
        self::assertSame(
            ':002 DB 001 END 1000 I ab12 0000000F',
            UdbWireCodec::end('002', '001', 1000, UdbBlock::Ips, 'ab12', 'F'),
        );
        self::assertSame(
            ':002 DB 001 ACK 1000 I ab12 0000000F',
            UdbWireCodec::ack('002', '001', 1000, UdbBlock::Ips, 'ab12', 'F'),
        );
        self::assertSame(':002 DB 001 ERR PUT 3 1000 C', UdbWireCodec::err('002', '001', 'PUT', 3, 1000, UdbBlock::Channels));
        self::assertSame(':002 DB 001 ERR INS 6 7 0', UdbWireCodec::err('002', '001', 'INS', 6, 7, null));
        self::assertSame(
            ':002 DB * INS C::%23chan%3A%3Afounder :alice',
            UdbWireCodec::ins('002', 'C', '%23chan%3A%3Afounder', 'alice'),
        );
        self::assertSame(':002 DB * DEL C::%23chan', UdbWireCodec::del('002', 'C', '%23chan'));
    }

    #[Test]
    public function checksumArgumentsAreNormalized(): void
    {
        self::assertStringEndsWith('INF 5 N ABCDEF12 1', UdbWireCodec::inf('002', '001', 5, UdbBlock::Nicks, 'abcdef12', 1));
        self::assertStringEndsWith('BEGIN 5 N tx 00000000', UdbWireCodec::begin('002', '001', 5, UdbBlock::Nicks, 'tx', 'zz'));
    }

    #[Test]
    public function parseHelFrame(): void
    {
        $frame = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 HEL 4 ircd.example.net 0123456789abcdef OCL OCLG'));

        self::assertNotNull($frame);
        self::assertSame(UdbFrameKind::Hel, $frame->kind);
        self::assertSame('001', $frame->sourceSid);
        self::assertSame('002', $frame->target);
        self::assertSame('ircd.example.net', $frame->propagator);
        self::assertSame('0123456789abcdef', $frame->epoch);
        self::assertSame(['OCL', 'OCLG'], $frame->capabilities);
    }

    #[Test]
    public function parseHelWithQuestionMarkPropagator(): void
    {
        $frame = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 HEL 4 ? 0123456789abcdef OCL'));

        self::assertNotNull($frame);
        self::assertSame('?', $frame->propagator);
    }

    #[Test]
    public function parseHelRejectsTooManyCapabilities(): void
    {
        self::assertNull(UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 HEL 4 ircd.example.net 0123456789abcdef OCL OCLG EXTRA')));
    }

    #[Test]
    public function parseHelAckFrame(): void
    {
        $frame = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 HEL 4 ACK ircd.example.net 0123456789abcdef OCL'));

        self::assertNotNull($frame);
        self::assertSame(UdbFrameKind::HelAck, $frame->kind);
        self::assertSame('ircd.example.net', $frame->propagator);
        self::assertSame('0123456789abcdef', $frame->epoch);
    }

    #[Test]
    public function parseOclgSnapshotFrames(): void
    {
        $digest = str_repeat('a', 64);
        $begin = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 OCLG BEGIN 0123456789abcdef 7 READY 1 ' . $digest));
        self::assertNotNull($begin);
        self::assertSame(UdbFrameKind::OclgBegin, $begin->kind);
        self::assertSame('READY', $begin->status);
        self::assertSame(1, $begin->count);
        self::assertSame($digest, $begin->checksum);

        $item = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 OCLG ITEM 0123456789abcdef 7 netadmin ' . $digest));
        self::assertNotNull($item);
        self::assertSame(UdbFrameKind::OclgItem, $item->kind);
        self::assertSame('netadmin', $item->path);

        $end = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 OCLG END 0123456789abcdef 7'));
        self::assertNotNull($end);
        self::assertSame(UdbFrameKind::OclgEnd, $end->kind);
    }

    #[Test]
    public function oclgViewDigestMatchesTheUdbBinaryEncoding(): void
    {
        self::assertSame('95d9b200b13758900b688f6197420a6b12bcf7fcdfe7218e783dad0f8017164c', UdbOclgViewDigest::fromEntries(true, ['netadmin' => str_repeat('a', 64)]));
        self::assertSame(
            'c434656abd1d23f99fd609235eccd31e3b55f8637e17188095578632800ea68a',
            UdbOclgViewDigest::fromEntries(true, [
                'admin' => '863629b21b7030c7ac64b5efa87ffdff4fb0f034c3ef2e6fe66f9ebf9e28bc01',
                'admin-with-override' => 'f1be89317d9ba42023689cc23467b17604c57876b2025da6d2cef09aa39915e9',
                'globop' => 'dbc66c42b1ed0f3c876e749221f887f3249b814b17ef46b26d22a553ff81129e',
                'globop-with-override' => '7fd2a14f823030a9f7eda0a7f18922f789afa7eb5841cf7732c7fe7f22e79223',
                'locop' => '881fd8dd85c448fc344749c479a92841839f0c0c8d6b02304b789204d4e3fec2',
                'netadmin' => '4f7b74230fa9367d54bdd84ff0502ddd179c3e84859bce2c386b1a392a08ef84',
                'netadmin-with-override' => '362dfcb4b18bf9a6a048e85fcdefe910f5b9ab5ea9104a2b05444f077338c405',
                'services-admin' => '01ccbd8bb8fee1ec15ef1eca2ba27c3102603242a83856e51b30281129c41813',
                'services-admin-with-override' => 'ad4593fb6ff658db0bf7a18e4533f47b714557025c77ce1d3dc19bf334e69230',
            ]),
        );
        self::assertTrue(UdbOclgViewDigest::isValid(str_repeat('a', 64)));
        self::assertFalse(UdbOclgViewDigest::isValid('A'));
    }

    #[Test]
    public function parseInfFrame(): void
    {
        $frame = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 INF 999 C abcdef12 1700000000'));

        self::assertNotNull($frame);
        self::assertSame(UdbFrameKind::Inf, $frame->kind);
        self::assertSame(999, $frame->roundId);
        self::assertSame(UdbBlock::Channels, $frame->block);
        self::assertSame('ABCDEF12', $frame->checksum);
        self::assertSame(1700000000, $frame->timestamp);
    }

    #[Test]
    public function parseResFrame(): void
    {
        $frame = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 RES 999 K'));

        self::assertNotNull($frame);
        self::assertSame(UdbFrameKind::Res, $frame->kind);
        self::assertSame(999, $frame->roundId);
        self::assertSame(UdbBlock::Lines, $frame->block);
    }

    #[Test]
    public function parseBeginFrame(): void
    {
        $frame = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 BEGIN 999 S tx_1 00ABCDEF'));

        self::assertNotNull($frame);
        self::assertSame(UdbFrameKind::Begin, $frame->kind);
        self::assertSame(UdbBlock::Settings, $frame->block);
        self::assertSame('tx_1', $frame->txid);
        self::assertSame('00ABCDEF', $frame->checksum);
    }

    #[Test]
    public function parsePutWithTrailingValue(): void
    {
        $frame = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 PUT 999 S tx_1 nickserv :NickServ!NickServ@host'));

        self::assertNotNull($frame);
        self::assertSame(UdbFrameKind::Put, $frame->kind);
        self::assertSame('nickserv', $frame->path);
        self::assertSame('NickServ!NickServ@host', $frame->value);
    }

    #[Test]
    public function parsePutWithNumericParamValue(): void
    {
        $frame = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 PUT 999 I tx 1.2.3.4::clones *5'));

        self::assertNotNull($frame);
        self::assertSame('1.2.3.4::clones', $frame->path);
        self::assertSame('*5', $frame->value);
    }

    #[Test]
    public function parseEndAndAckFrames(): void
    {
        $end = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 END 999 S tx_1 00ABCDEF'));
        self::assertNotNull($end);
        self::assertSame(UdbFrameKind::End, $end->kind);
        self::assertSame('00ABCDEF', $end->checksum);

        $ackTrailing = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 ACK 999 S tx_1 :00ABCDEF'));
        self::assertNotNull($ackTrailing);
        self::assertSame(UdbFrameKind::Ack, $ackTrailing->kind);
        self::assertSame('00ABCDEF', $ackTrailing->checksum);
    }

    #[Test]
    public function parseErrFrame(): void
    {
        $frame = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 ERR PUT 3 999 C'));
        self::assertNotNull($frame);
        self::assertSame(UdbFrameKind::Err, $frame->kind);
        self::assertSame('PUT', $frame->subcommand);
        self::assertSame(3, $frame->errorCode);
        self::assertSame(999, $frame->roundId);
        self::assertSame(UdbBlock::Channels, $frame->block);

        $blockless = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB 002 ERR INS 2 42 0'));
        self::assertNotNull($blockless);
        self::assertNull($blockless->block);
    }

    #[Test]
    public function parseInsAndDelAndDrpAndOptFrames(): void
    {
        $ins = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB * INS S::nickserv :mask value'));
        self::assertNotNull($ins);
        self::assertSame(UdbFrameKind::Ins, $ins->kind);
        self::assertSame('S::nickserv', $ins->path);
        self::assertSame('mask value', $ins->value);

        $del = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB * DEL S::nickserv'));
        self::assertNotNull($del);
        self::assertSame(UdbFrameKind::Del, $del->kind);
        self::assertSame('S::nickserv', $del->path);

        $drp = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB * DRP I'));
        self::assertNotNull($drp);
        self::assertSame(UdbFrameKind::Drp, $drp->kind);
        self::assertSame(UdbBlock::Ips, $drp->block);

        $opt = UdbWireCodec::parse(IRCMessage::fromRawLine(':001 DB * OPT S'));
        self::assertNotNull($opt);
        self::assertSame(UdbFrameKind::Opt, $opt->kind);
        self::assertSame(UdbBlock::Settings, $opt->block);
    }

    #[Test]
    public function parseRejectsMalformedFrames(): void
    {
        $malformed = [
            'PRIVMSG #chan :hello',
            ':001 DB',
            ':001 DB 002 HEL 5 x',
            ':001 DB 002 HEL 4 ircd.example.net 0123456789abcdef',
            ':001 DB 002 HEL 4 ircd.example.net 0123456789abcdef OCL OCL',
            ':001 DB 002 HEL 4 ircd.example.net 0123456789abcdef OCL UNKNOWN',
            ':001 DB 002 HEL 4 ircd.example.net 0123456789ABCDEf OCL',
            ':001 DB 002 HEL 4 ACK ircd.example.net 0123456789abcdef OCL OCLG UNKNOWN',
            ':001 DB 002 HEL',
            ':001 DB 002 HEL 4',
            ':001 DB 002 INF 0 N 00 1',
            ':001 DB 002 INF 999 X 00 1',
            ':001 DB 002 INF 999 N zz 1',
            ':001 DB 002 RES 0 N',
            ':001 DB 002 RES 999 X',
            ':001 DB 002 BEGIN 999 N bad.txid 00',
            ':001 DB 002 PUT 999 N tx bad%41path :v',
            ':001 DB 002 PUT 999 N tx path',
            ':001 DB 002 PUT 999 N tx path :',
            ':001 DB 002 END 999 N tx',
            ':001 DB 002 ACK 999 N tx NOHEX',
            ':001 DB 002 ERR PUT 256 999 C',
            ':001 DB 002 ERR PUT 3 999 X',
            ':001 DB * INS %41path :v',
            ':001 DB * INS S::%41 :v',
            ':001 DB * DEL S::%41',
            ':001 DB * DEL',
            ':001 DB * DRP X',
            ':001 DB * OPT X',
            ':001 DB 002 OCLG BEGIN 0123456789abcdef 7 READY 1 short',
            ':001 DB 002 OCLG ITEM 0123456789abcdef 7 netadmin short',
            ':001 DB 002 OCLG END 0123456789abcdef 7 extra',
            ':001 DB 002 OCLG FOO 0123456789abcdef 7',
            ':001 DB 002 OCLG BEGIN NOTHEX16 7 READY 1 ' . str_repeat('a', 64),
            ':UNKNOWN 002 001 HEL 4 x',
        ];

        foreach ($malformed as $raw) {
            self::assertNull(UdbWireCodec::parse(IRCMessage::fromRawLine($raw)), "Expected null for: {$raw}");
        }
    }

    #[Test]
    public function parseRejectsAdditionalMalformedWireShapes(): void
    {
        $malformed = [
            ':001 DB 002 HEL 4',              // missing HEL fields
            ':001 DB 002 INF 999 N 00',       // INF with 5 params
            ':001 DB 002 RES 999',            // RES with 3 params
            ':001 DB 002 BEGIN 999 S tx',     // BEGIN with 5 params
            ':001 DB 002 BEGIN 999 S tx NOHEX',
            ':001 DB 002 PUT 999 N tx',       // PUT with 5 params
            ':001 DB 002 PUT 0 N tx path :v', // PUT with zero round
            ':001 DB 002 END 999 N',          // END with 4 params
            ':001 DB 002 ACK 999 N',          // ACK with 4 params
            ':001 DB 002 END 0 N tx 00',
            ':001 DB 002 ERR PUT 3 999',      // ERR with 5 params
            ':001 DB * INS',                  // INS without path
            ':001 DB * INS S::nickserv',      // INS without value
            ':001 DB * DRP',                  // DRP without letter
            ':001 DB * OPT',                  // OPT without letter
        ];

        foreach ($malformed as $raw) {
            self::assertNull(UdbWireCodec::parse(IRCMessage::fromRawLine($raw)), "Expected null for: {$raw}");
        }
    }

    #[Test]
    public function parseRejectsEmptyPropagatorParameter(): void
    {
        $message = new IRCMessage(command: 'DB', prefix: '001', params: ['002', 'HEL', '4', '', '0123456789abcdef', 'OCL']);

        self::assertNull(UdbWireCodec::parse($message));
    }
}
