<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAuthorityState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbBlockStateDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbChannelSuspendReasonResolver;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbWireTakeover;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbWireTakeoverOutcome;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbWireTakeoverOutcomeKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrameKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbStructuralNodeCount;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbWireCodec;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;
use function strlen;

#[CoversClass(UdbWireTakeover::class)]
#[CoversClass(UdbWireTakeoverOutcome::class)]
#[CoversClass(UdbStructuralNodeCount::class)]
final class UdbWireTakeoverTest extends DoctrineIntegrationTestCase
{
    private UdbRecordRepositoryInterface $records;

    private UdbBlockStateRepositoryInterface $states;

    private EntityManagerInterface $em;

    private UdbWireTakeover $takeover;

    private MutableUdbClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->records = new UdbRecordDoctrineRepository($this->entityManager);
        $this->states = new UdbBlockStateDoctrineRepository($this->entityManager);
        $this->em = $this->entityManager;
        UdbStagingSchemaFixture::create($this->entityManager->getConnection());
        $this->clock = new MutableUdbClock();
        $this->takeover = new UdbWireTakeover(
            $this->records,
            $this->states,
            $this->em,
            $this->createEmptyExporter(),
            clock: $this->clock,
        );
    }

    #[Test]
    public function fullNativeTxidAndBinaryStringSurviveCorrelatedTransferAndPromotion(): void
    {
        $txid = str_repeat('t', 31);
        $bytes = "fixture\x01\xff\x80\\x4142";
        $records = ['quit_ips' => $bytes];
        $outcome = $this->stagedTransferFor(UdbBlock::Settings, $txid, $records);
        $this->assertAcknowledgement($outcome, UdbBlock::Settings, 1, $txid, UdbTestValues::digest(UdbBlock::Settings, $records));
        self::assertTrue(UdbPathCodec::isValidTxid($txid));
        self::assertFalse(UdbPathCodec::isValidTxid($txid . 't'));
        $this->completeOtherBlocks(UdbBlock::Settings);
        self::assertTrue($this->takeover->canPromote());
        $this->takeover->finalize();
        $this->em->clear();
        self::assertSame($records, UdbTestValues::logicalMap($this->records->recordsByBlock('S')));
    }

    #[Test]
    public function realTypedChannelAccessTransferAcknowledgesCanonicalDigestAndCount(): void
    {
        $block = UdbBlock::Channels;
        $rows = [
            '#chan::access::alice' => UdbValue::none(),
            '#chan::access::bob' => UdbValue::string('*1'),
            '#chan::access::carol' => UdbValue::number(UdbUnsignedDecimal::fromInt(1)),
            '#chan::access::dave' => UdbValue::string('*level'),
        ];
        $digest = UdbChecksum::fromMap($block, $rows);
        $this->assertRequest($this->inventory($block, digest: $digest, count: count($rows)), $block, 1);
        $this->assertIgnored($this->begin($block, 'typed', $digest));
        foreach ($rows as $path => $value) {
            $frame = UdbWireCodec::parse(IRCMessage::fromRawLine(UdbWireCodec::put('001', '002', 1, $block, 'typed', $path, $value)));
            self::assertNotNull($frame);
            $this->assertIgnored($this->takeover->accept($frame));
        }
        $stored = $this->em->getConnection()->fetchAllAssociative('SELECT path, value, value_kind FROM udb_staged_records ORDER BY id');
        self::assertCount(4, $stored);
        foreach ($stored as $row) {
            self::assertIsString($row['path']);
            self::assertSame($rows[$row['path']]->kind->value, $row['value_kind']);
            self::assertSame($rows[$row['path']]->logicalValue(), $row['value']);
        }
        $this->assertAcknowledgement($this->end($block, 'typed', $digest), $block, 1, 'typed', $digest);
        self::assertSame(['C'], $this->takeover->completedBlocks());
        self::assertFalse($this->takeover->canPromote());
        self::assertSame([], $this->records->recordsByBlock('C'));
    }

    /** @return iterable<string, array{UdbBlock, string, UdbValue}> */
    public static function nativeRejectedRows(): iterable
    {
        yield 'channel protected mode' => [UdbBlock::Channels, '#chan::modes', UdbValue::string('+P')];
        yield 'channel member mode' => [UdbBlock::Channels, '#chan::modes', UdbValue::string('-q nick')];
        foreach (['G' => '*@host', 'Z' => '192.0.2.1', 'S' => '*@host', 'Q' => 'badnick', 'F' => 'b64%3AYQ=='] as $type => $mask) {
            yield 'line prefix ' . $type => [UdbBlock::Lines, $type . '::' . $mask, UdbValue::none()];
        }
    }

    #[Test]
    #[DataProvider('nativeRejectedRows')]
    public function schemaInvalidTypedPutDiscardsStageWithoutAcknowledgementOrPromotion(UdbBlock $block, string $path, UdbValue $value): void
    {
        $this->inventory($block);
        $this->begin($block, 'invalid');
        $frame = UdbWireCodec::parse(IRCMessage::fromRawLine(UdbWireCodec::put('001', '002', 1, $block, 'invalid', $path, $value)));
        self::assertNotNull($frame);
        $this->assertError($this->takeover->accept($frame), $block, 1, 'PUT', 2);
        self::assertSame([], $this->takeover->completedBlocks());
        self::assertFalse($this->takeover->canPromote());
        self::assertSame([], $this->records->recordsByBlock($block->letter()));
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM udb_staged_records');
        self::assertIsInt($count);
        self::assertSame(0, $count);
    }

    #[Test]
    #[DataProvider('nativeRejectedRows')]
    public function schemaInvalidTypedInsCannotStageOrPromote(UdbBlock $block, string $path, UdbValue $value): void
    {
        $this->inventory($block);
        $frame = UdbWireCodec::parse(IRCMessage::fromRawLine(UdbWireCodec::ins('001', '1111111111111111', 1, $block->letter(), $path, $value)));
        self::assertNotNull($frame);
        $this->assertError($this->takeover->acceptMutation($frame), $block, 1, 'INS', 2);
        self::assertFalse($this->takeover->canPromote());
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM udb_staged_mutations');
        self::assertIsInt($count);
        self::assertSame(0, $count);
    }

    #[Test]
    public function stagedForbidTailReplacesOnlyItsCanonicalNickSiblings(): void
    {
        $rows = ['Alice::pass' => 'crypt:fixture', 'alice::vhost' => 'fixture.test', 'bob::pass' => 'crypt:other'];
        $outcome = $this->stagedTransfer(UdbBlock::Nicks, $rows);
        $this->assertAcknowledgement($outcome, UdbBlock::Nicks, 1, 'tx-N', UdbTestValues::digest(UdbBlock::Nicks, $rows));
        foreach (UdbBlock::all() as $block) {
            if (UdbBlock::Nicks !== $block) {
                $this->assertIgnored($this->inventory($block, digest: UdbChecksum::empty($block)));
            }
        }
        $mutation = new UdbFrame(UdbFrameKind::Ins, '001', '*', block: UdbBlock::Nicks, epoch: '1111111111111111', sequence: 1, path: 'N::ALICE::FoRbId', typedValue: UdbValue::string('fixture reason'));
        $this->assertIgnored($this->takeover->acceptMutation($mutation));
        $this->assertIgnored($this->takeover->acceptMutation($mutation));
        $updated = new UdbFrame(UdbFrameKind::Ins, '001', '*', block: UdbBlock::Nicks, epoch: '1111111111111111', sequence: 2, path: 'N::ALICE::FoRbId', typedValue: UdbValue::string('updated reason'));
        $this->assertIgnored($this->takeover->acceptMutation($updated));
        $this->takeover->markTailReplayComplete();
        self::assertTrue($this->takeover->canPromote());
        $this->takeover->finalize();
        // Native NickServ projection never replaces local account authority.
        self::assertSame([], $this->records->recordsByBlock('N'));
    }

    #[Test]
    public function inventoryEstablishesOneRoundAndRequestsEachBlockOnce(): void
    {
        $this->assertRequest($this->inventory(UdbBlock::Ips), UdbBlock::Ips, 1);
        self::assertSame(80, $this->takeover->nextDeadline());

        $this->assertIgnored($this->inventory(UdbBlock::Ips));
        $this->assertError($this->inventory(UdbBlock::Settings, 2), UdbBlock::Settings, 2, 'INF', 5);

        $this->clock->advance(10);
        $this->assertRequest($this->inventory(UdbBlock::Settings), UdbBlock::Settings, 1);
        self::assertSame(90, $this->takeover->nextDeadline());
    }

    #[Test]
    public function inconsistentInventoryWatermarksAbortTheWholeRound(): void
    {
        $this->assertRequest($this->inventory(UdbBlock::Ips, watermark: 5), UdbBlock::Ips, 1);

        $outcome = $this->takeover->accept($this->frame(
            UdbFrameKind::Inf,
            UdbBlock::Settings,
            checksum: UdbChecksum::empty(UdbBlock::Settings),
            count: 0,
            watermark: 6,
        ));

        $this->assertError($outcome, UdbBlock::Settings, 1, 'INF', 3);
        self::assertNull($this->takeover->nextDeadline());
    }

    #[Test]
    public function inventoryWithoutADigestReturnsProtocolError(): void
    {
        $outcome = $this->takeover->accept($this->frame(UdbFrameKind::Inf, UdbBlock::Ips));

        $this->assertError($outcome, UdbBlock::Ips, 1, 'INF', 2);
    }

    #[Test]
    public function endRejectsAStageWhoseStructuralNodeCountDiffersFromTheInventory(): void
    {
        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $this->assertRequest($this->inventory(UdbBlock::Ips, digest: $digest, count: 2), UdbBlock::Ips, 1);
        $this->assertIgnored($this->begin(UdbBlock::Ips, 'tx1', $digest));
        $this->assertIgnored($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*5'));

        $this->assertError($this->end(UdbBlock::Ips, 'tx1', $digest), UdbBlock::Ips, 1, 'END', 3);
    }

    #[Test]
    public function beginRequiresTheBlockResHandshakeAndAllowsOnlyOneActiveTransfer(): void
    {
        $this->assertError($this->begin(UdbBlock::Ips, 'tx1'), UdbBlock::Ips, 1, 'BEGIN', 5);

        $this->assertRequest($this->inventory(UdbBlock::Ips), UdbBlock::Ips, 1);
        $this->assertIgnored($this->begin(UdbBlock::Ips, 'tx1'));
        $this->assertError($this->begin(UdbBlock::Ips, 'tx2'), UdbBlock::Ips, 1, 'BEGIN', 4);
    }

    #[Test]
    public function beginMustMatchTheInventoryDigestAndWatermark(): void
    {
        $this->assertRequest($this->inventory(UdbBlock::Ips, watermark: 5), UdbBlock::Ips, 1);

        $digestMismatch = $this->takeover->accept($this->frame(
            UdbFrameKind::Begin,
            UdbBlock::Ips,
            'tx1',
            str_repeat('a', 64),
            watermark: 5,
        ));
        $this->assertError($digestMismatch, UdbBlock::Ips, 1, 'BEGIN', 3);

        $watermarkMismatch = $this->takeover->accept($this->frame(
            UdbFrameKind::Begin,
            UdbBlock::Ips,
            'tx1',
            UdbChecksum::empty(UdbBlock::Ips),
            watermark: 6,
        ));
        $this->assertError($watermarkMismatch, UdbBlock::Ips, 1, 'BEGIN', 3);
    }

    #[Test]
    public function importsWireModeledBlocksOnFinalizeAndAcknowledgesTheTransfer(): void
    {
        $outcome = $this->stagedTransfer(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $this->assertAcknowledgement($outcome, UdbBlock::Ips, 1, 'tx-I', $digest);

        self::assertSame(['I'], $this->takeover->completedBlocks());
        self::assertSame([], $this->records->recordsByBlock('I'));

        $this->completeOtherBlocks(UdbBlock::Ips);
        $fingerprint = $this->takeover->finalize();

        self::assertSame(64, strlen($fingerprint));
        self::assertTrue(['1.2.3.4::clones' => UdbTestValues::fromLogical(UdbBlock::Ips, '1.2.3.4::clones', '*5')] == $this->records->recordsByBlock('I'));
        self::assertSame($digest, $this->states->all()['I']->getDigest());
        self::assertSame(1, $this->states->all()['I']->getRecordCount());
    }

    #[Test]
    public function relayedRootMutationIsSequencedAndReplayedBeforePromotion(): void
    {
        $rootSid = '003';
        $rootEpoch = '1111111111111111';
        $watermark = 41;
        foreach (UdbBlock::all() as $block) {
            $outcome = $this->takeover->accept(new UdbFrame(
                UdbFrameKind::Inf,
                '001', // Authorized direct peer remains distinct from the stream root.
                '002',
                roundId: 9,
                block: $block,
                checksum: UdbChecksum::empty($block),
                count: 0,
                watermark: $watermark,
                rootSid: $rootSid,
                rootEpoch: $rootEpoch,
            ));
            $this->assertIgnored($outcome); // Equal empty roots are durably reused.
        }

        $path = 'I::1.2.3.4::clones';
        $insert = new UdbFrame(
            UdbFrameKind::Ins,
            $rootSid,
            '*',
            block: UdbBlock::Ips,
            epoch: $rootEpoch,
            sequence: 42,
            path: $path,
            value: '!u5',
            typedValue: UdbTestValues::fromLogical(UdbBlock::Ips, '1.2.3.4::clones', '*5'),
        );
        $this->assertIgnored($this->takeover->acceptMutation($insert));
        $this->assertIgnored($this->takeover->acceptMutation($insert)); // Canonical duplicate is idempotent.
        $this->assertError(
            $this->takeover->acceptMutation(new UdbFrame(
                UdbFrameKind::Ins,
                '001',
                '*',
                block: UdbBlock::Ips,
                epoch: $rootEpoch,
                sequence: 43,
                path: 'I::1.2.3.5::clones',
                value: '!u4',
                typedValue: UdbTestValues::fromLogical(UdbBlock::Ips, '1.2.3.5::clones', '*4'),
            )),
            UdbBlock::Ips,
            9,
            'INS',
            5,
        );
        $this->assertError(
            $this->takeover->acceptMutation(new UdbFrame(
                UdbFrameKind::Ins,
                $rootSid,
                '*',
                block: UdbBlock::Ips,
                epoch: $rootEpoch,
                sequence: 44,
                path: 'I::1.2.3.5::clones',
                value: '!u4',
                typedValue: UdbTestValues::fromLogical(UdbBlock::Ips, '1.2.3.5::clones', '*4'),
            )),
            UdbBlock::Ips,
            9,
            'INS',
            5,
        );

        $this->takeover->markTailReplayComplete();
        $this->takeover->finalize();

        self::assertTrue(
            ['1.2.3.4::clones' => UdbTestValues::fromLogical(UdbBlock::Ips, '1.2.3.4::clones', '*5')] == $this->records->recordsByBlock('I'),
        );
        $authority = $this->entityManager->find(UdbAuthorityState::class, 1);
        self::assertInstanceOf(UdbAuthorityState::class, $authority);
        $imported = $authority->getImportedCheckpoint();
        self::assertNotNull($imported);
        self::assertSame($rootSid, $imported->origin->sid);
        self::assertSame($rootEpoch, $imported->origin->epoch);
        self::assertSame('42', (string) $imported->watermark);
    }

    #[Test]
    public function stagedPutCanonicalizesNumericValuesBeforeDigestAndPersistence(): void
    {
        $canonicalDigest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $this->assertRequest($this->inventory(UdbBlock::Ips, digest: $canonicalDigest, count: 1), UdbBlock::Ips, 1);
        $this->assertIgnored($this->begin(UdbBlock::Ips, 'tx1', $canonicalDigest));
        $this->assertIgnored($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*0005'));
        $this->assertAcknowledgement($this->end(UdbBlock::Ips, 'tx1', $canonicalDigest), UdbBlock::Ips, 1, 'tx1', $canonicalDigest);

        $this->completeOtherBlocks(UdbBlock::Ips);
        $this->takeover->finalize();

        self::assertTrue(['1.2.3.4::clones' => UdbTestValues::fromLogical(UdbBlock::Ips, '1.2.3.4::clones', '*5')] == $this->records->recordsByBlock('I'));
    }

    #[Test]
    public function sqlOwnedBlocksAreAcknowledgedButDiscarded(): void
    {
        $records = ['alice::vhost' => 'alice.tld'];
        $digest = UdbTestValues::digest(UdbBlock::Nicks, ['alice::vhost' => 'alice.tld']);

        $outcome = $this->stagedTransferFor(UdbBlock::Nicks, 'tx1', $records);
        $this->assertAcknowledgement($outcome, UdbBlock::Nicks, 1, 'tx1', $digest);
        self::assertSame(['N'], $this->takeover->completedBlocks());

        $this->completeOtherBlocks(UdbBlock::Nicks);
        $this->takeover->finalize();

        self::assertSame([], $this->records->recordsByBlock('N'));
    }

    #[Test]
    public function digestMismatchReturnsErrAndLeavesTheBlockIncomplete(): void
    {
        $this->inventory(UdbBlock::Ips);
        $this->begin(UdbBlock::Ips, 'tx1');
        $this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*5');

        $outcome = $this->end(UdbBlock::Ips, 'tx1', str_repeat('a', 8));

        $this->assertError($outcome, UdbBlock::Ips, 1, 'END', 3);
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function schemaInvalidRecordReturnsErrAndDiscardsTheStage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('UDB wire bootstrap: staged transfer discarded.', ['block' => 'I', 'reason' => 'schema-invalid PUT record']);
        $this->takeover = $this->takeoverWithLogger($logger);

        $this->inventory(UdbBlock::Ips);
        $this->begin(UdbBlock::Ips, 'tx1');
        $this->assertError($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', 'not-a-mask'), UdbBlock::Ips, 1, 'PUT', 2);
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', UdbChecksum::empty(UdbBlock::Ips)), UdbBlock::Ips, 1, 'END', 5);

        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function aggregateInvalidSpamfilterReturnsErrAtEnd(): void
    {
        $pattern = 'b64%3A' . base64_encode('(');
        $records = [
            "F::{$pattern}::match-type" => 'regex',
            "F::{$pattern}::targets" => 'c',
            "F::{$pattern}::action" => 'kill',
            "F::{$pattern}::reason" => 'blocked',
        ];

        $this->assertError(
            $this->stagedTransferFor(UdbBlock::Lines, 'tx1', $records),
            UdbBlock::Lines,
            1,
            'END',
            2,
        );
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function putAndEndWithoutAnOpenStageReturnErr(): void
    {
        $this->inventory(UdbBlock::Ips);

        $this->assertError($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*5'), UdbBlock::Ips, 1, 'PUT', 5);
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', UdbChecksum::empty(UdbBlock::Ips)), UdbBlock::Ips, 1, 'END', 5);
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function finalizeRejectsAnIncompleteRoundWithoutChangingTheStore(): void
    {
        $this->stagedTransfer(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot finalize an incomplete UDB wire takeover round.');

        try {
            $this->takeover->finalize();
        } finally {
            self::assertSame([], $this->records->recordsByBlock('I'));
            self::assertSame([], $this->states->all());
        }
    }

    #[Test]
    public function mismatchedTxidAndRoundReturnErrWithoutAdvancingTheStage(): void
    {
        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $this->inventory(UdbBlock::Ips, digest: $digest, count: 1);
        $this->begin(UdbBlock::Ips, 'tx1', $digest);

        $this->assertError($this->put(UdbBlock::Ips, 'wrong', '1.2.3.4::clones', '*5'), UdbBlock::Ips, 1, 'PUT', 5);
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', $digest, 99), UdbBlock::Ips, 99, 'END', 5);

        $this->assertIgnored($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*5'));
        $this->assertAcknowledgement($this->end(UdbBlock::Ips, 'tx1', $digest), UdbBlock::Ips, 1, 'tx1', $digest);
    }

    #[Test]
    public function incompleteAndUnrelatedFramesAreIgnored(): void
    {
        $this->assertIgnored($this->takeover->accept(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1)));
        $this->assertIgnored($this->takeover->accept(new UdbFrame(UdbFrameKind::Begin, '001', '002', block: UdbBlock::Ips)));
        $this->assertIgnored($this->takeover->accept(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 1, block: UdbBlock::Ips)));

        $this->inventory(UdbBlock::Ips);
        $this->assertIgnored($this->takeover->accept($this->frame(UdbFrameKind::Begin, UdbBlock::Ips)));
        $this->assertIgnored($this->takeover->accept($this->frame(UdbFrameKind::End, UdbBlock::Ips)));
    }

    #[Test]
    public function duplicatePutPathReturnsErrAndDiscardsTheStage(): void
    {
        $this->inventory(UdbBlock::Ips);
        $this->begin(UdbBlock::Ips, 'tx1');
        $this->assertIgnored($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*5'));

        $this->assertError($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*2'), UdbBlock::Ips, 1, 'PUT', 2);
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', UdbChecksum::empty(UdbBlock::Ips)), UdbBlock::Ips, 1, 'END', 5);
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function onlyAnExactRetransmittedEndIsAcknowledgedAgain(): void
    {
        $first = $this->stagedTransfer(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        self::assertNotNull($first->digest);

        $this->assertError($this->begin(UdbBlock::Ips, 'tx-next', $first->digest), UdbBlock::Ips, 1, 'BEGIN', 4);
        $this->assertError($this->end(UdbBlock::Ips, 'wrong', $first->digest), UdbBlock::Ips, 1, 'END', 5);
        $this->assertError($this->end(UdbBlock::Ips, 'tx-I', str_repeat('a', 8)), UdbBlock::Ips, 1, 'END', 5);
        $this->assertAcknowledgement($this->end(UdbBlock::Ips, 'tx-I', $first->digest), UdbBlock::Ips, 1, 'tx-I', $first->digest);

        self::assertSame(['I'], $this->takeover->completedBlocks());
    }

    #[Test]
    public function isCompleteRequiresEveryBlockFromTheSameRound(): void
    {
        $this->stagedTransfer(UdbBlock::Nicks, ['alice::vhost' => 'alice.tld']);
        $this->stagedTransfer(UdbBlock::Channels, ['#chan::topic' => 'welcome']);
        $this->stagedTransfer(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $this->stagedTransfer(UdbBlock::Settings, ['propagator' => 'hub1.example']);
        $this->stagedTransfer(UdbBlock::Links, ['hub1.example::options' => '*1']);
        self::assertFalse($this->takeover->isComplete());

        $this->assertError($this->inventory(UdbBlock::Lines, 2), UdbBlock::Lines, 2, 'INF', 5);
        self::assertFalse($this->takeover->isComplete());

        $this->stagedTransfer(UdbBlock::Lines, ['G::*@bad.example::reason' => 'reason']);
        self::assertTrue($this->takeover->isComplete());
    }

    #[Test]
    public function finalizeReplacesSqlBlocksFromSqlAndKeepsWireBlocks(): void
    {
        $this->stagedTransfer(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $this->stagedTransfer(UdbBlock::Settings, ['propagator' => 'hub1.example']);
        $this->stagedTransfer(UdbBlock::Links, ['hub1.example::options' => '*1']);
        $this->stagedTransfer(UdbBlock::Nicks, ['alice::vhost' => 'alice.tld']);
        $this->stagedTransfer(UdbBlock::Channels, ['#chan::topic' => 'welcome']);
        $this->stagedTransfer(UdbBlock::Lines, ['G::*@bad.example::reason' => 'reason']);

        self::assertTrue($this->takeover->isComplete());
        self::assertFalse($this->takeover->canPromote());
        $this->takeover->markTailReplayComplete();
        self::assertTrue($this->takeover->canPromote());

        $this->takeover->finalize();

        self::assertTrue(['1.2.3.4::clones' => UdbTestValues::fromLogical(UdbBlock::Ips, '1.2.3.4::clones', '*5')] == $this->records->recordsByBlock('I'));
        self::assertTrue(['propagator' => UdbTestValues::fromLogical(UdbBlock::Settings, 'propagator', 'hub1.example')] == $this->records->recordsByBlock('S'));
        self::assertTrue(['hub1.example::options' => UdbTestValues::fromLogical(UdbBlock::Links, 'hub1.example::options', '*1')] == $this->records->recordsByBlock('L'));
        self::assertSame([], $this->records->recordsByBlock('N'));
        self::assertSame([], $this->records->recordsByBlock('C'));
        self::assertSame([], $this->records->recordsByBlock('K'));
    }

    #[Test]
    public function resetDropsTheRoundAndAllowsACompletelyFreshRound(): void
    {
        $this->stagedTransfer(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $this->takeover->reset();

        self::assertSame([], $this->takeover->completedBlocks());
        self::assertFalse($this->takeover->isComplete());
        self::assertNull($this->takeover->nextDeadline());
        $this->assertRequest($this->inventory(UdbBlock::Settings, 2), UdbBlock::Settings, 2);
    }

    #[Test]
    public function anInventoryOnlyRoundExpiresOnInactivity(): void
    {
        $this->inventory(UdbBlock::Ips);
        self::assertSame(80, $this->takeover->nextDeadline());

        $this->clock->advance(59);
        self::assertFalse($this->takeover->expire());
        $this->clock->advance(1);
        self::assertTrue($this->takeover->expire());
        self::assertNull($this->takeover->nextDeadline());
        $this->assertRequest($this->inventory(UdbBlock::Settings, 2), UdbBlock::Settings, 2);
    }

    #[Test]
    public function duplicateAndInvalidTrafficCannotRefreshRoundInactivity(): void
    {
        $this->inventory(UdbBlock::Ips);
        self::assertSame(80, $this->takeover->nextDeadline());

        $this->clock->advance(20);
        $this->assertIgnored($this->inventory(UdbBlock::Ips));
        $this->assertError($this->inventory(UdbBlock::Settings, 2), UdbBlock::Settings, 2, 'INF', 5);
        self::assertSame(80, $this->takeover->nextDeadline());

        $this->assertIgnored($this->begin(UdbBlock::Ips, 'tx1'));
        self::assertSame(100, $this->takeover->nextDeadline());
        $this->clock->advance(20);
        $this->assertError($this->begin(UdbBlock::Ips, 'tx2'), UdbBlock::Ips, 1, 'BEGIN', 4);
        $this->assertError($this->put(UdbBlock::Ips, 'wrong', '1.2.3.4::clones', '*5'), UdbBlock::Ips, 1, 'PUT', 5);
        self::assertSame(100, $this->takeover->nextDeadline());

        $this->clock->advance(40);
        self::assertTrue($this->takeover->expire());
    }

    #[Test]
    public function anOlderStageCanExpireEvenWhenOtherRoundProgressIsRecent(): void
    {
        $this->inventory(UdbBlock::Ips);
        $this->begin(UdbBlock::Ips, 'tx1');
        $this->clock->advance(30);
        $this->inventory(UdbBlock::Settings);
        self::assertSame(80, $this->takeover->nextDeadline());

        $this->clock->advance(30);
        self::assertTrue($this->takeover->expire());
        self::assertNull($this->takeover->nextDeadline());
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function lateStageProgressReturnsErrAndResetsTheWholeRound(): void
    {
        $this->inventory(UdbBlock::Ips);
        $this->begin(UdbBlock::Ips, 'tx1');
        $this->clock->advance(60);

        $this->assertError($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*5'), UdbBlock::Ips, 1, 'PUT', 5);

        self::assertNull($this->takeover->nextDeadline());
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function validProgressCannotExtendTheRoundBeyondItsAbsoluteDeadline(): void
    {
        $this->inventory(UdbBlock::Ips);
        $this->begin(UdbBlock::Ips, 'tx1');
        foreach ([50, 50, 50, 50, 50] as $index => $advance) {
            $this->clock->advance($advance);
            $this->assertIgnored($this->put(UdbBlock::Ips, 'tx1', '192.0.2.' . ($index + 1) . '::clones', '*5'));
        }

        self::assertSame(320, $this->takeover->nextDeadline());
        $this->clock->advance(50);
        self::assertTrue($this->takeover->expire());
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function recordLimitExhaustionReturnsErrAndDiscardsTheStage(): void
    {
        $this->takeover->maxStageRecords = 1;
        $this->inventory(UdbBlock::Ips);
        $this->begin(UdbBlock::Ips, 'tx1');
        $this->assertIgnored($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*5'));

        $this->assertError($this->put(UdbBlock::Ips, 'tx1', '5.6.7.8::clones', '*2'), UdbBlock::Ips, 1, 'PUT', 2);
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', UdbChecksum::empty(UdbBlock::Ips)), UdbBlock::Ips, 1, 'END', 5);
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function oversizedRecordReturnsErrAndDiscardsTheStage(): void
    {
        $this->inventory(UdbBlock::Ips);
        $this->begin(UdbBlock::Ips, 'tx1');

        $this->assertError($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', str_repeat('x', 5000)), UdbBlock::Ips, 1, 'PUT', 2);
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', UdbChecksum::empty(UdbBlock::Ips)), UdbBlock::Ips, 1, 'END', 5);
    }

    #[Test]
    public function byteLimitExhaustionReturnsErrAndDiscardsTheStage(): void
    {
        $this->takeover->maxStageBytes = 10;
        $this->inventory(UdbBlock::Ips);
        $this->begin(UdbBlock::Ips, 'tx1');

        $this->assertError($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*5'), UdbBlock::Ips, 1, 'PUT', 2);
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', UdbChecksum::empty(UdbBlock::Ips)), UdbBlock::Ips, 1, 'END', 5);
    }

    #[Test]
    public function matchingNonemptyNativeRootReusesDurableRowsAndReplaysInsertDeleteAndDrop(): void
    {
        $this->records->seedBlock('I', ['192.0.2.1::clones' => UdbValue::number(UdbUnsignedDecimal::fromInt(5))]);
        $digest = UdbTestValues::digest(UdbBlock::Ips, ['192.0.2.1::clones' => '*5']);
        $this->assertIgnored($this->inventory(UdbBlock::Ips, digest: $digest, count: 1));
        foreach (UdbBlock::all() as $block) {
            if (UdbBlock::Ips !== $block) {
                $this->assertIgnored($this->inventory($block, digest: UdbChecksum::empty($block)));
            }
        }
        self::assertTrue($this->takeover->isComplete());
        self::assertFalse($this->takeover->canPromote());
        foreach ([
            new UdbFrame(UdbFrameKind::Ins, '001', '*', block: UdbBlock::Ips, epoch: '1111111111111111', sequence: 1, path: 'I::192.0.2.1::clones', typedValue: UdbValue::number(UdbUnsignedDecimal::fromInt(7))),
            new UdbFrame(UdbFrameKind::Ins, '001', '*', block: UdbBlock::Ips, epoch: '1111111111111111', sequence: 2, path: 'I::192.0.2.2::clones', typedValue: UdbValue::number(UdbUnsignedDecimal::fromInt(8))),
            new UdbFrame(UdbFrameKind::Del, '001', '*', block: UdbBlock::Ips, epoch: '1111111111111111', sequence: 3, path: 'I::192.0.2.1'),
            new UdbFrame(UdbFrameKind::Drp, '001', '*', block: UdbBlock::Ips, epoch: '1111111111111111', sequence: 4),
            new UdbFrame(UdbFrameKind::Ins, '001', '*', block: UdbBlock::Ips, epoch: '1111111111111111', sequence: 5, path: 'I::192.0.2.3::clones', typedValue: UdbValue::number(UdbUnsignedDecimal::fromInt(9))),
        ] as $mutation) {
            $this->assertIgnored($this->takeover->acceptMutation($mutation));
        }
        self::assertTrue(['192.0.2.1::clones' => UdbValue::number(UdbUnsignedDecimal::fromInt(5))] == $this->records->recordsByBlock('I'));
        $this->takeover->markTailReplayComplete();
        self::assertTrue($this->takeover->canPromote());
        $this->takeover->finalize();
        self::assertTrue(['192.0.2.3::clones' => UdbValue::number(UdbUnsignedDecimal::fromInt(9))] == $this->records->recordsByBlock('I'));
        self::assertContains($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM udb_staged_mutations'), [0, '0']);
    }

    #[Test]
    #[DataProvider('tamperedStages')]
    public function persistedGenerationTamperingCannotPromoteAnyLiveRecord(string $table, string $column, int|string $value): void
    {
        $this->stagedTransfer(UdbBlock::Ips, ['192.0.2.1::clones' => '*5']);
        $this->completeOtherBlocks(UdbBlock::Ips);
        $connection = $this->em->getConnection();
        $connection->executeStatement('UPDATE ' . $table . ' SET ' . $column . ' = ?', [$value]);
        try {
            $this->takeover->finalize();
            self::fail('A corrupted persisted generation was promoted.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('UDB', $exception->getMessage());
            self::assertContains($connection->fetchOne('SELECT COUNT(*) FROM udb_records'), [0, '0']);
            self::assertFalse($connection->isTransactionActive());
        }
    }

    /** @return iterable<string, array{string, string, string|int}> */
    public static function tamperedStages(): iterable
    {
        yield 'root sid' => ['udb_staged_generations', 'root_sid', '009'];
        yield 'root epoch' => ['udb_staged_generations', 'root_epoch', '2222222222222222'];
        yield 'root round' => ['udb_staged_generations', 'round_id', '9'];
        yield 'root watermark' => ['udb_staged_generations', 'watermark', '9'];
        yield 'already promoted' => ['udb_staged_generations', 'promoted', 1];
        yield 'transfer round' => ['udb_staged_transfers', 'round_id', '9'];
        yield 'transfer watermark' => ['udb_staged_transfers', 'watermark', '9'];
        yield 'transfer digest' => ['udb_staged_transfers', 'digest', str_repeat('a', 64)];
        yield 'transfer expected count' => ['udb_staged_transfers', 'expected_count', 2];
        yield 'transfer count' => ['udb_staged_transfers', 'record_count', 2];
        yield 'transfer incomplete' => ['udb_staged_transfers', 'complete', 0];
        yield 'record payload' => ['udb_staged_records', 'value', '*6'];
    }

    /**
     * @param array<string, string> $records
     */
    private function stagedTransfer(UdbBlock $block, array $records): UdbWireTakeoverOutcome
    {
        return $this->stagedTransferFor($block, 'tx-' . $block->letter(), $records);
    }

    /**
     * @param array<string, string> $records
     */
    private function stagedTransferFor(UdbBlock $block, string $txid, array $records, int $roundId = 1): UdbWireTakeoverOutcome
    {
        $digest = UdbTestValues::digest($block, $records);

        if ([] === $records) {
            return $this->inventory($block, $roundId, $digest, 0);
        }
        $this->assertRequest($this->inventory($block, $roundId, $digest, count($records)), $block, $roundId);
        $this->assertIgnored($this->begin($block, $txid, $digest, $roundId));
        foreach ($records as $path => $value) {
            $this->assertIgnored($this->put($block, $txid, $path, $value, $roundId));
        }

        return $this->end($block, $txid, $digest, $roundId);
    }

    private function completeOtherBlocks(UdbBlock $completedBlock): void
    {
        foreach (UdbBlock::all() as $block) {
            if ($completedBlock !== $block) {
                $this->assertIgnored($this->inventory($block, digest: UdbChecksum::empty($block)));
            }
        }
        $this->takeover->markTailReplayComplete();
    }

    private function inventory(UdbBlock $block, int $roundId = 1, ?string $digest = null, int $count = 0, int $watermark = 0): UdbWireTakeoverOutcome
    {
        return $this->takeover->accept($this->frame(UdbFrameKind::Inf, $block, checksum: $digest ?? hash('sha256', 'remote-divergent-' . $block->letter()), roundId: $roundId, count: $count, watermark: $watermark));
    }

    private function begin(UdbBlock $block, ?string $txid, ?string $checksum = null, int $roundId = 1): UdbWireTakeoverOutcome
    {
        return $this->takeover->accept($this->frame(UdbFrameKind::Begin, $block, $txid, $checksum ?? hash('sha256', 'remote-divergent-' . $block->letter()), roundId: $roundId, watermark: 0));
    }

    private function put(UdbBlock $block, string $txid, string $path, string $value, int $roundId = 1): UdbWireTakeoverOutcome
    {
        return $this->takeover->accept($this->frame(UdbFrameKind::Put, $block, $txid, path: $path, value: $value, roundId: $roundId));
    }

    private function end(UdbBlock $block, ?string $txid, ?string $checksum = null, int $roundId = 1): UdbWireTakeoverOutcome
    {
        return $this->takeover->accept($this->frame(UdbFrameKind::End, $block, $txid, $checksum ?? hash('sha256', 'remote-divergent-' . $block->letter()), roundId: $roundId, watermark: 0));
    }

    private function assertIgnored(UdbWireTakeoverOutcome $outcome): void
    {
        self::assertSame(UdbWireTakeoverOutcomeKind::Ignored, $outcome->kind);
    }

    private function assertRequest(UdbWireTakeoverOutcome $outcome, UdbBlock $block, int $roundId): void
    {
        self::assertSame(UdbWireTakeoverOutcomeKind::Request, $outcome->kind);
        self::assertSame($block, $outcome->block);
        self::assertSame((string) $roundId, (string) $outcome->roundId);
    }

    private function assertAcknowledgement(UdbWireTakeoverOutcome $outcome, UdbBlock $block, int $roundId, string $txid, string $digest): void
    {
        self::assertSame(UdbWireTakeoverOutcomeKind::Acknowledge, $outcome->kind);
        self::assertSame($block, $outcome->block);
        self::assertSame((string) $roundId, (string) $outcome->roundId);
        self::assertSame($txid, $outcome->txid);
        self::assertSame($digest, $outcome->digest);
    }

    private function assertError(UdbWireTakeoverOutcome $outcome, UdbBlock $block, int $roundId, string $subcommand, int $errorCode): void
    {
        self::assertSame(UdbWireTakeoverOutcomeKind::Error, $outcome->kind);
        self::assertSame($block, $outcome->block);
        self::assertSame((string) $roundId, (string) $outcome->roundId);
        self::assertSame($subcommand, $outcome->subcommand);
        self::assertSame($errorCode, $outcome->errorCode);
    }

    private function takeoverWithLogger(LoggerInterface $logger): UdbWireTakeover
    {
        return new UdbWireTakeover($this->records, $this->states, $this->em, $this->createEmptyExporter(), $logger, $this->clock);
    }

    private function createEmptyExporter(): UdbRecordExporter
    {
        return new UdbRecordExporter(
            $this->createStub(NickProjectionQuery::class),
            $this->createStub(ChannelProjectionQuery::class),
            $this->createStub(OperatorNetworkProjectionQuery::class),
            $this->createStub(GlineProjectionQuery::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            new UdbChannelSuspendReasonResolver($this->createStub(TranslatorInterface::class), 'ChanServ'),
        );
    }

    private function frame(
        UdbFrameKind $kind,
        UdbBlock $block,
        ?string $txid = null,
        ?string $checksum = null,
        ?string $path = null,
        ?string $value = null,
        int $roundId = 1,
        ?int $count = null,
        ?int $watermark = null,
    ): UdbFrame {
        $typedValue = null !== $path && null !== $value ? (UdbSchema::typedValueForStoredRecord($block->letter(), $path, $value) ?? UdbValue::string($value)) : null;

        return new UdbFrame($kind, '001', '002', roundId: $roundId, block: $block, txid: $txid, checksum: $checksum, path: $path, value: $value, count: $count, watermark: $watermark, typedValue: $typedValue, rootSid: '001', rootEpoch: '1111111111111111');
    }

    #[Test]
    public function malformedTailPathsAndSequenceGapsReturnErrorsWithoutStaging(): void
    {
        $this->inventory(UdbBlock::Ips, digest: str_repeat('a', 64));
        foreach (['missingseparator', 'I::', '?::x'] as $path) {
            $outcome = $this->takeover->acceptMutation(new UdbFrame(UdbFrameKind::Del, '001', '*', epoch: '1111111111111111', sequence: 1, path: $path));
            self::assertSame(UdbWireTakeoverOutcomeKind::Error, $outcome->kind);
        }
        $outcome = $this->takeover->acceptMutation(new UdbFrame(UdbFrameKind::Del, '001', '*', epoch: '1111111111111111', sequence: 2, path: 'I::192.0.2.1'));
        self::assertSame(UdbWireTakeoverOutcomeKind::Error, $outcome->kind);
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM udb_staged_mutations'));
    }

    #[Test]
    public function missingBeginAndEndMetadataCannotAcknowledgeAStage(): void
    {
        $digest = str_repeat('a', 64);
        $this->inventory(UdbBlock::Ips, digest: $digest);
        $this->assertIgnored($this->takeover->accept($this->frame(UdbFrameKind::Begin, UdbBlock::Ips, checksum: $digest)));
        $this->assertError($this->takeover->accept($this->frame(UdbFrameKind::Begin, UdbBlock::Ips, txid: 'tx1', checksum: $digest)), UdbBlock::Ips, 1, 'BEGIN', 3);
        $this->assertError($this->takeover->accept($this->frame(UdbFrameKind::End, UdbBlock::Ips, txid: 'tx1', checksum: $digest)), UdbBlock::Ips, 1, 'END', 2);
        new ReflectionProperty(UdbWireTakeover::class, 'generationId')->setValue($this->takeover, null);
        $this->assertError($this->begin(UdbBlock::Ips, 'tx1', $digest), UdbBlock::Ips, 1, 'BEGIN', 5);
    }

    #[Test]
    public function realSqlInsertFailureRollsBackBeginBeforeStageAdmission(): void
    {
        $digest = str_repeat('a', 64);
        $this->inventory(UdbBlock::Ips, digest: $digest);
        $this->em->getConnection()->executeStatement("CREATE TRIGGER reject_transfer BEFORE INSERT ON udb_staged_transfers BEGIN SELECT RAISE(ABORT, 'controlled failure'); END");
        try {
            $this->begin(UdbBlock::Ips, 'tx1', $digest);
            self::fail('Failed SQL cannot admit a stage.');
        } catch (Exception $error) {
            self::assertStringContainsString('controlled failure', $error->getMessage());
        }
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM udb_staged_transfers'));
    }

    #[Test]
    public function realSqlGenerationFailureRollsBackItsMetadata(): void
    {
        $this->em->getConnection()->executeStatement("CREATE TRIGGER reject_generation BEFORE INSERT ON udb_staged_generations BEGIN SELECT RAISE(ABORT, 'controlled failure'); END");
        try {
            $this->inventory(UdbBlock::Ips, digest: str_repeat('a', 64));
            self::fail('Failed SQL cannot establish a generation.');
        } catch (Exception $error) {
            self::assertStringContainsString('controlled failure', $error->getMessage());
        }
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    #[Test]
    public function reusableBlockCopyFailureRollsBackItsPartialRows(): void
    {
        $this->inventory(UdbBlock::Ips, digest: str_repeat('a', 64));
        $this->records->upsert('I', '192.0.2.1::clones', UdbValue::number(UdbUnsignedDecimal::fromInt(1)));
        $this->em->getConnection()->executeStatement("CREATE TRIGGER reject_reuse BEFORE INSERT ON udb_staged_transfers BEGIN SELECT RAISE(ABORT, 'controlled failure'); END");
        try {
            new ReflectionMethod(UdbWireTakeover::class, 'stageReusableBlock')->invoke($this->takeover, UdbBlock::Ips, UdbUnsignedDecimal::fromInt(1), str_repeat('a', 64), 1);
            self::fail('Failed reuse cannot leave partial copied rows.');
        } catch (Exception $error) {
            self::assertStringContainsString('controlled failure', $error->getMessage());
        }
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM udb_staged_records'));
    }

    #[Test]
    public function completionUpdateFailureCannotGrantDurableAcknowledgement(): void
    {
        $digest = UdbTestValues::digest(UdbBlock::Ips, ['192.0.2.1::clones' => '*1']);
        $this->inventory(UdbBlock::Ips, digest: $digest, count: 1);
        $this->begin(UdbBlock::Ips, 'tx1', $digest);
        $this->put(UdbBlock::Ips, 'tx1', '192.0.2.1::clones', '*1');
        $this->em->getConnection()->executeStatement("CREATE TRIGGER reject_completion BEFORE UPDATE ON udb_staged_transfers BEGIN SELECT RAISE(ABORT, 'controlled failure'); END");
        try {
            $this->end(UdbBlock::Ips, 'tx1', $digest);
            self::fail('Failed completion cannot grant ACK credit.');
        } catch (Exception $error) {
            self::assertStringContainsString('controlled failure', $error->getMessage());
        }
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function corruptedTailBlockRollsBackReplayBeforeApproval(): void
    {
        $this->inventory(UdbBlock::Ips, digest: str_repeat('a', 64));
        $generation = new ReflectionProperty(UdbWireTakeover::class, 'generationId')->getValue($this->takeover);
        $this->em->getConnection()->insert('udb_staged_mutations', ['generation_id' => $generation, 'sequence' => '1', 'block' => '?', 'operation' => 'DRP', 'path' => '', 'value' => null, 'value_kind' => null]);
        try {
            new ReflectionMethod(UdbWireTakeover::class, 'replayTail')->invoke($this->takeover);
            self::fail('Corrupt staged block cannot be replayed.');
        } catch (LogicException $error) {
            self::assertStringContainsString('invalid block', $error->getMessage());
        }
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertFalse($this->takeover->canPromote());
    }

    #[Test]
    public function replayedAggregateConflictIsRejectedTransactionally(): void
    {
        $this->inventory(UdbBlock::Nicks, digest: str_repeat('a', 64));
        $generation = new ReflectionProperty(UdbWireTakeover::class, 'generationId')->getValue($this->takeover);
        foreach ([['nick::forbid', 'yes'], ['nick::vhost', 'fixture.test']] as $index => [$path, $value]) {
            $this->em->getConnection()->insert('udb_staged_mutations', ['generation_id' => $generation, 'sequence' => (string) ($index + 1), 'block' => 'N', 'operation' => 'INS', 'path' => $path, 'value' => $value, 'value_kind' => 1]);
        }
        try {
            new ReflectionMethod(UdbWireTakeover::class, 'replayTail')->invoke($this->takeover);
            self::fail('Conflicting aggregate cannot be approved.');
        } catch (LogicException $error) {
            self::assertStringContainsString('validation', $error->getMessage());
        }
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM udb_staged_records'));
    }

    #[Test]
    public function oversizedPutAndLostGenerationNeverReceiveAcknowledgements(): void
    {
        $digest = str_repeat('a', 64);
        $this->inventory(UdbBlock::Ips, digest: $digest);
        $this->begin(UdbBlock::Ips, 'tx1', $digest);
        $this->assertError($this->takeover->accept($this->frame(UdbFrameKind::Put, UdbBlock::Ips, txid: 'tx1', path: '192.0.2.1::host', value: str_repeat('x', 4097))), UdbBlock::Ips, 1, 'PUT', 2);
        $this->takeover->reset();
        $this->inventory(UdbBlock::Ips, digest: $digest);
        $this->begin(UdbBlock::Ips, 'tx1', $digest);
        new ReflectionProperty(UdbWireTakeover::class, 'generationId')->setValue($this->takeover, null);
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', $digest), UdbBlock::Ips, 1, 'END', 5);
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function oversizedTypedPathAbortsTheStageWithoutAck(): void
    {
        $digest = str_repeat('a', 64);
        $this->inventory(UdbBlock::Ips, digest: $digest);
        $this->begin(UdbBlock::Ips, 'tx1', $digest);
        $outcome = $this->takeover->accept(new UdbFrame(UdbFrameKind::Put, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', path: str_repeat('x', 8193), value: 'x', typedValue: UdbValue::string('x')));
        $this->assertError($outcome, UdbBlock::Ips, 1, 'PUT', 2);
    }
}
