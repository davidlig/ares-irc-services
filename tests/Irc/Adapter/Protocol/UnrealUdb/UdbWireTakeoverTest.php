<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbWireTakeover;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbWireTakeoverOutcome;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbWireTakeoverOutcomeKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrameKind;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use App\Shared\Application\Port\ActiveChannelModeSupportProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function array_keys;
use function array_map;
use function strlen;

#[CoversClass(UdbWireTakeover::class)]
#[CoversClass(UdbWireTakeoverOutcome::class)]
final class UdbWireTakeoverTest extends TestCase
{
    private FakeUdbRecords $records;

    private FakeBlockStates $states;

    private EntityManagerInterface $em;

    private UdbWireTakeover $takeover;

    private MutableUdbClock $clock;

    protected function setUp(): void
    {
        $this->records = new FakeUdbRecords();
        $this->states = new FakeBlockStates();
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->em->method('wrapInTransaction')->willReturnCallback(static function (callable $callback): void {
            $callback();
        });
        $this->em->method('clear');
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
    public function beginRequiresTheBlockResHandshakeAndAllowsOnlyOneActiveTransfer(): void
    {
        $this->assertError($this->begin(UdbBlock::Ips, 'tx1'), UdbBlock::Ips, 1, 'BEGIN', 5);

        $this->assertRequest($this->inventory(UdbBlock::Ips), UdbBlock::Ips, 1);
        $this->assertIgnored($this->begin(UdbBlock::Ips, 'tx1'));
        $this->assertError($this->begin(UdbBlock::Ips, 'tx2'), UdbBlock::Ips, 1, 'BEGIN', 4);
    }

    #[Test]
    public function importsWireModeledBlocksOnFinalizeAndAcknowledgesTheTransfer(): void
    {
        $outcome = $this->stagedTransfer(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        $this->assertAcknowledgement($outcome, UdbBlock::Ips, 1, 'tx-I', $digest);

        self::assertSame(['I'], $this->takeover->completedBlocks());
        self::assertSame([], $this->records->recordsByBlock('I'));

        $this->completeOtherBlocks(UdbBlock::Ips);
        $fingerprint = $this->takeover->finalize();

        self::assertSame(64, strlen($fingerprint));
        self::assertSame(['1.2.3.4::clones' => '*5'], $this->records->recordsByBlock('I'));
        self::assertSame($digest, $this->states->states['I']->getChecksum());
    }

    #[Test]
    public function sqlOwnedBlocksAreAcknowledgedButDiscarded(): void
    {
        $records = ['alice::vhost' => 'alice.tld'];
        $digest = UdbChecksum::fromRecords([['alice::vhost', 'alice.tld']]);

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
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', UdbChecksum::EMPTY), UdbBlock::Ips, 1, 'END', 5);

        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function putAndEndWithoutAnOpenStageReturnErr(): void
    {
        $this->inventory(UdbBlock::Ips);

        $this->assertError($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*5'), UdbBlock::Ips, 1, 'PUT', 5);
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', UdbChecksum::EMPTY), UdbBlock::Ips, 1, 'END', 5);
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
            self::assertSame([], $this->states->states);
        }
    }

    #[Test]
    public function mismatchedTxidAndRoundReturnErrWithoutAdvancingTheStage(): void
    {
        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        $this->inventory(UdbBlock::Ips);
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
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', UdbChecksum::EMPTY), UdbBlock::Ips, 1, 'END', 5);
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
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', UdbChecksum::EMPTY), UdbBlock::Ips, 1, 'END', 5);
        self::assertSame([], $this->takeover->completedBlocks());
    }

    #[Test]
    public function oversizedRecordReturnsErrAndDiscardsTheStage(): void
    {
        $this->inventory(UdbBlock::Ips);
        $this->begin(UdbBlock::Ips, 'tx1');

        $this->assertError($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', str_repeat('x', 5000)), UdbBlock::Ips, 1, 'PUT', 2);
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', UdbChecksum::EMPTY), UdbBlock::Ips, 1, 'END', 5);
    }

    #[Test]
    public function byteLimitExhaustionReturnsErrAndDiscardsTheStage(): void
    {
        $this->takeover->maxStageBytes = 10;
        $this->inventory(UdbBlock::Ips);
        $this->begin(UdbBlock::Ips, 'tx1');

        $this->assertError($this->put(UdbBlock::Ips, 'tx1', '1.2.3.4::clones', '*5'), UdbBlock::Ips, 1, 'PUT', 2);
        $this->assertError($this->end(UdbBlock::Ips, 'tx1', UdbChecksum::EMPTY), UdbBlock::Ips, 1, 'END', 5);
    }

    /** @param array<string, string> $records */
    private function stagedTransfer(UdbBlock $block, array $records): UdbWireTakeoverOutcome
    {
        return $this->stagedTransferFor($block, 'tx-' . $block->letter(), $records);
    }

    /** @param array<string, string> $records */
    private function stagedTransferFor(UdbBlock $block, string $txid, array $records, int $roundId = 1): UdbWireTakeoverOutcome
    {
        $tuples = array_map(
            static fn (string $path, string $value): array => [$path, $value],
            array_keys($records),
            $records,
        );
        $digest = UdbChecksum::fromRecords($tuples);

        $this->assertRequest($this->inventory($block, $roundId), $block, $roundId);
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
                $this->stagedTransfer($block, []);
            }
        }
    }

    private function inventory(UdbBlock $block, int $roundId = 1): UdbWireTakeoverOutcome
    {
        return $this->takeover->accept($this->frame(UdbFrameKind::Inf, $block, roundId: $roundId));
    }

    private function begin(UdbBlock $block, ?string $txid, ?string $checksum = null, int $roundId = 1): UdbWireTakeoverOutcome
    {
        return $this->takeover->accept($this->frame(UdbFrameKind::Begin, $block, $txid, $checksum ?? str_repeat('0', 8), roundId: $roundId));
    }

    private function put(UdbBlock $block, string $txid, string $path, string $value, int $roundId = 1): UdbWireTakeoverOutcome
    {
        return $this->takeover->accept($this->frame(UdbFrameKind::Put, $block, $txid, path: $path, value: $value, roundId: $roundId));
    }

    private function end(UdbBlock $block, ?string $txid, ?string $checksum = null, int $roundId = 1): UdbWireTakeoverOutcome
    {
        return $this->takeover->accept($this->frame(UdbFrameKind::End, $block, $txid, $checksum, roundId: $roundId));
    }

    private function assertIgnored(UdbWireTakeoverOutcome $outcome): void
    {
        self::assertSame(UdbWireTakeoverOutcomeKind::Ignored, $outcome->kind);
    }

    private function assertRequest(UdbWireTakeoverOutcome $outcome, UdbBlock $block, int $roundId): void
    {
        self::assertSame(UdbWireTakeoverOutcomeKind::Request, $outcome->kind);
        self::assertSame($block, $outcome->block);
        self::assertSame($roundId, $outcome->roundId);
    }

    private function assertAcknowledgement(UdbWireTakeoverOutcome $outcome, UdbBlock $block, int $roundId, string $txid, string $digest): void
    {
        self::assertSame(UdbWireTakeoverOutcomeKind::Acknowledge, $outcome->kind);
        self::assertSame($block, $outcome->block);
        self::assertSame($roundId, $outcome->roundId);
        self::assertSame($txid, $outcome->txid);
        self::assertSame($digest, $outcome->digest);
    }

    private function assertError(UdbWireTakeoverOutcome $outcome, UdbBlock $block, int $roundId, string $subcommand, int $errorCode): void
    {
        self::assertSame(UdbWireTakeoverOutcomeKind::Error, $outcome->kind);
        self::assertSame($block, $outcome->block);
        self::assertSame($roundId, $outcome->roundId);
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
    ): UdbFrame {
        return new UdbFrame($kind, '001', '002', roundId: $roundId, block: $block, txid: $txid, checksum: $checksum, path: $path, value: $value);
    }
}
