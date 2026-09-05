<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbChecksum;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrame;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrameKind;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbRecordExporter;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbWireTakeover;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function array_keys;
use function array_map;
use function strlen;

#[CoversClass(UdbWireTakeover::class)]
final class UdbWireTakeoverTest extends TestCase
{
    private FakeUdbRecords $records;

    private FakeBlockStates $states;

    private EntityManagerInterface $em;

    private UdbWireTakeover $takeover;

    protected function setUp(): void
    {
        $this->records = new FakeUdbRecords();
        $this->states = new FakeBlockStates();
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->em->method('wrapInTransaction')->willReturnCallback(static function (callable $callback): void {
            $callback();
        });
        $this->em->method('clear');
        $this->takeover = new UdbWireTakeover($this->records, $this->states, $this->em, $this->createEmptyExporter());
    }

    #[Test]
    public function importsWireModeledBlocksOnFinalizeAndAcknowledgesTheTransfer(): void
    {
        $digest = $this->stagedTransfer(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);

        // Staging validates and acknowledges; the store is only written on
        // finalize (atomic replacement).
        self::assertSame(['I'], $this->takeover->completedBlocks());
        self::assertSame([], $this->records->recordsByBlock('I'));

        $fingerprint = $this->takeover->finalize();

        self::assertSame(64, strlen($fingerprint));
        self::assertSame(['1.2.3.4::clones' => '*5'], $this->records->recordsByBlock('I'));
        self::assertSame($digest, $this->states->states['I']->getChecksum());
    }

    #[Test]
    public function sqlOwnedBlocksAreAcknowledgedButDiscarded(): void
    {
        $digest = UdbChecksum::fromRecords([['alice::vhost', 'alice.tld']]);
        $this->begin(UdbBlock::Nicks, 'tx1', $digest);
        $this->put(UdbBlock::Nicks, 'tx1', 'alice::vhost', 'alice.tld');

        $completedDigest = $this->end(UdbBlock::Nicks, 'tx1', $digest);

        self::assertSame($digest, $completedDigest);
        self::assertSame(['N'], $this->takeover->completedBlocks());

        $this->takeover->finalize();

        // The peer N content is never merged: SQL owns the block.
        self::assertSame([], $this->records->recordsByBlock('N'));
    }

    #[Test]
    public function rejectsTheStagedTransferWhenTheDigestDoesNotMatch(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $takeover = new UdbWireTakeover($this->records, $this->states, $this->em, $this->createEmptyExporter(), $logger);

        $takeover->accept($this->frame(UdbFrameKind::Begin, UdbBlock::Ips, 'tx1', UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']])));
        $takeover->accept($this->frame(UdbFrameKind::Put, UdbBlock::Ips, 'tx1', null, '1.2.3.4::clones', '*5'));

        self::assertNull($takeover->accept($this->frame(UdbFrameKind::End, UdbBlock::Ips, 'tx1', str_repeat('a', 8))));

        self::assertSame([], $takeover->completedBlocks());
    }

    #[Test]
    public function rejectsSchemaInvalidRecords(): void
    {
        $this->begin(UdbBlock::Ips, 'tx1');
        $this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', 'not-a-mask');

        self::assertNull($this->takeover->accept($this->frame(UdbFrameKind::End, UdbBlock::Ips, 'tx1', UdbChecksum::EMPTY)));
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function putFramesWithoutAnOpenStageAreIgnored(): void
    {
        self::assertNull($this->takeover->accept($this->frame(UdbFrameKind::Put, UdbBlock::Ips, 'tx1', null, '1.2.3.4::clones', '*5')));
        self::assertNull($this->takeover->accept($this->frame(UdbFrameKind::End, UdbBlock::Ips, 'tx1', UdbChecksum::EMPTY)));
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function mismatchedTxidOrRoundIsIgnored(): void
    {
        $this->begin(UdbBlock::Ips, 'tx1');
        $this->put(UdbBlock::Ips, 'WRONG-TX', '1.2.3.4::clones', '*5');

        $this->end(UdbBlock::Ips, 'tx1', UdbChecksum::EMPTY, 99);

        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function framesWithoutBlockOrUnknownKindAreIgnored(): void
    {
        self::assertNull($this->takeover->accept(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1)));
        self::assertNull($this->takeover->accept(new UdbFrame(UdbFrameKind::Inf, '001', '002', roundId: 1, block: UdbBlock::Ips)));
    }

    #[Test]
    public function duplicateEndForACompletedBlockIsIgnored(): void
    {
        $digest = $this->stagedTransfer(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);

        // A second END has no open stage: strictly ignored, nothing changes.
        self::assertNull($this->takeover->accept($this->frame(UdbFrameKind::End, UdbBlock::Ips, 'tx1', $digest)));
        self::assertSame(['I'], $this->takeover->completedBlocks());
    }

    #[Test]
    public function isCompleteRequiresEveryBlock(): void
    {
        $this->stagedTransfer(UdbBlock::Nicks, ['alice::vhost' => 'alice.tld']);
        $this->stagedTransfer(UdbBlock::Channels, ['#chan::topic' => 'welcome']);
        $this->stagedTransfer(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $this->stagedTransfer(UdbBlock::Settings, ['propagator' => 'hub1.example']);
        $this->stagedTransfer(UdbBlock::Links, ['hub1.example::options' => '*1']);
        self::assertFalse($this->takeover->isComplete());

        $this->stagedTransfer(UdbBlock::Lines, ['G::1.2.3.4' => 'reason']);
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
        $this->stagedTransfer(UdbBlock::Lines, ['G::1.2.3.4' => 'reason']);

        $this->takeover->finalize();

        self::assertSame(['1.2.3.4::clones' => '*5'], $this->records->recordsByBlock('I'));
        self::assertSame(['propagator' => 'hub1.example'], $this->records->recordsByBlock('S'));
        self::assertSame(['hub1.example::options' => '*1'], $this->records->recordsByBlock('L'));
        // SQL-backed blocks are rebuilt from SQL (empty export here): the
        // peer content is never merged.
        self::assertSame([], $this->records->recordsByBlock('N'));
        self::assertSame([], $this->records->recordsByBlock('C'));
        self::assertSame([], $this->records->recordsByBlock('K'));
    }

    #[Test]
    public function resetDropsAllStagedAndCompletedState(): void
    {
        $this->stagedTransfer(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $this->takeover->reset();

        self::assertSame([], $this->takeover->completedBlocks());
        self::assertFalse($this->takeover->isComplete());
    }

    #[Test]
    public function retransmittedBeginOfACompletedBlockIsAcknowledgedAgain(): void
    {
        $digest = $this->stagedTransfer(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);

        $completed = $this->takeover->accept($this->frame(UdbFrameKind::Begin, UdbBlock::Ips, 'tx1', $digest));

        self::assertNotNull($completed);
        self::assertSame($digest, $completed['digest']);
        self::assertSame(['I'], $this->takeover->completedBlocks());
    }

    #[Test]
    public function recordLimitExhaustionDiscardsTheStage(): void
    {
        $this->takeover->maxStageRecords = 1;

        $this->begin(UdbBlock::Ips, 'tx1');
        $this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*5');
        $this->put(UdbBlock::Ips, 'tx1', '5.6.7.8::clones', '*2');

        self::assertNull($this->takeover->accept($this->frame(UdbFrameKind::End, UdbBlock::Ips, 'tx1', UdbChecksum::EMPTY)));
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function oversizedRecordDiscardsTheStage(): void
    {
        $this->begin(UdbBlock::Ips, 'tx1');
        $this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', str_repeat('x', 5000));

        self::assertNull($this->takeover->accept($this->frame(UdbFrameKind::End, UdbBlock::Ips, 'tx1', UdbChecksum::EMPTY)));
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function byteLimitExhaustionDiscardsTheStage(): void
    {
        $this->takeover->maxStageBytes = 10;

        $this->begin(UdbBlock::Ips, 'tx1');
        $this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*5');
        $this->put(UdbBlock::Ips, 'tx1', '5.6.7.8::clones', '*5');

        // The byte overflow aborted the stage, so the END finds nothing.
        self::assertNull($this->takeover->accept($this->frame(UdbFrameKind::End, UdbBlock::Ips, 'tx1', UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]))));
        self::assertSame([], $this->takeover->completedBlocks());
    }

    // ---------- Helpers ----------

    /** @param array<string, string> $records */
    private function stagedTransfer(UdbBlock $block, array $records): string
    {
        return $this->stagedTransferFor($block, 'tx-' . $block->letter(), $records);
    }

    /** @param array<string, string> $records */
    private function stagedTransferFor(UdbBlock $block, string $txid, array $records): string
    {
        $tuples = array_map(
            static fn (string $path, string $value): array => [$path, $value],
            array_keys($records),
            $records,
        );
        $digest = UdbChecksum::fromRecords($tuples);

        $this->begin($block, $txid, $digest);
        foreach ($records as $path => $value) {
            $this->put($block, $txid, $path, $value);
        }

        return $this->end($block, $txid, $digest);
    }

    private function begin(UdbBlock $block, string $txid, ?string $checksum = null): void
    {
        $this->takeover->accept($this->frame(UdbFrameKind::Begin, $block, $txid, $checksum ?? str_repeat('0', 8)));
    }

    private function put(UdbBlock $block, string $txid, string $path, string $value): void
    {
        $this->takeover->accept($this->frame(UdbFrameKind::Put, $block, $txid, null, $path, $value));
    }

    private function end(UdbBlock $block, string $txid, ?string $checksum = null, int $roundId = 1): string
    {
        $completed = $this->takeover->accept($this->frame(UdbFrameKind::End, $block, $txid, $checksum, roundId: $roundId));

        return null !== $completed ? $completed['digest'] : '';
    }

    /** Exporter over empty repositories: the SQL export yields no records. */
    private function createEmptyExporter(): UdbRecordExporter
    {
        return new UdbRecordExporter(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(GlineRepositoryInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );
    }

    private function frame(
        UdbFrameKind $kind,
        UdbBlock $block,
        string $txid,
        ?string $checksum = null,
        ?string $path = null,
        ?string $value = null,
        int $roundId = 1,
    ): UdbFrame {
        return new UdbFrame($kind, '001', '002', roundId: $roundId, block: $block, txid: $txid, checksum: $checksum, path: $path, value: $value);
    }
}
