<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\UdbMutation;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbChecksum;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrame;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrameKind;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSessionCoordinator;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSnapshotProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

use function array_slice;
use function count;

final class UdbSessionCoordinatorTest extends TestCase
{
    private const string OWN_NAME = 'services.example.net';

    private FakeBlockStates $blockStates;

    private UdbSnapshotProviderInterface $snapshots;

    /** @var list<string> */
    private array $written = [];

    private ConnectionInterface $connection;

    private UdbSessionCoordinator $coordinator;

    protected function setUp(): void
    {
        $this->blockStates = new FakeBlockStates();
        $this->snapshots = $this->createStub(UdbSnapshotProviderInterface::class);
        $this->snapshots->method('recordsForBlock')->willReturnCallback(
            static fn (UdbBlock $block): array => UdbBlock::Ips === $block ? ['1.2.3.4::clones' => '*5'] : [],
        );
        $this->snapshots->method('checksumForBlock')->willReturnCallback(
            static fn (UdbBlock $block): string => UdbChecksum::fromRecords(
                UdbBlock::Ips === $block ? [['1.2.3.4::clones', '*5']] : [],
            ),
        );

        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        $this->written = [];
        $this->coordinator = new UdbSessionCoordinator('002', $this->blockStates, $this->snapshots);
    }

    // ---------- Link establishment ----------

    #[Test]
    public function linkReadyAnnouncesOurselvesAsPropagator(): void
    {
        $this->prepareLink();

        self::assertSame([':002 DB 001 HEL 4 ' . self::OWN_NAME], $this->written);
    }

    #[Test]
    public function helIsDeferredUntilOwnNameIsKnown(): void
    {
        $this->coordinator->onRemoteServer('001', 'ircd.example.net');
        $this->coordinator->onLinkReady($this->connection);

        self::assertSame([], $this->written);
    }

    #[Test]
    public function helIsDeferredUntilRemoteSidIsKnown(): void
    {
        $this->coordinator->setOwnName(self::OWN_NAME);
        $this->coordinator->onLinkReady($this->connection);

        self::assertSame([], $this->written);
    }

    #[Test]
    public function repeatedHelSendWithoutForceIsANoOp(): void
    {
        $this->prepareLink();
        $this->written = [];

        new ReflectionMethod(UdbSessionCoordinator::class, 'sendHel')->invoke($this->coordinator);

        self::assertSame([], $this->written);
    }

    // ---------- Reconciliation offering ----------

    #[Test]
    public function peerSelectingUsTriggersHelAckOurHelAndInventoryPlusBarrier(): void
    {
        $this->seedStore();
        $this->prepareLink();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));

        self::assertSame(':002 DB 001 HEL 4 ACK', $this->written[0]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N [0-9A-F]{8} \d+$/', $this->written[1]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ C /', $this->written[2]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ I /', $this->written[3]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ S /', $this->written[4]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ L /', $this->written[5]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ K /', $this->written[6]);
        self::assertSame(':002 DB 001 HEL 4 ' . self::OWN_NAME, $this->written[7]);
    }

    #[Test]
    public function questionMarkPropagatorAuthorizesUs(): void
    {
        $this->seedStore();
        $this->prepareLink();

        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: '?'));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));

        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function differentPropagatorDoesNotAuthorizeUs(): void
    {
        $this->seedStore();
        $this->prepareLink();

        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: 'ircd.example.net'));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));

        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function barrierAckCompletesReadiness(): void
    {
        $this->makeReady();

        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function readinessRequiresBarrierAck(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));

        // INF x6 + barrier HEL sent, but the barrier ACK has not arrived.
        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function offerIsSkippedWhileTheStoreIsNotInitialized(): void
    {
        $this->prepareLink();

        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));

        // Our own HEL (from link establishment) + the HEL ACK reply: no INF
        // round without a ready store.
        self::assertSame([
            ':002 DB 001 HEL 4 ' . self::OWN_NAME,
            ':002 DB 001 HEL 4 ACK',
        ], $this->written);
    }

    #[Test]
    public function onStoreInitializedReevaluatesAndOffers(): void
    {
        $this->prepareLink();
        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));

        $this->written = [];
        $this->seedStore();
        $this->coordinator->onStoreInitialized();

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->written[0]);
        self::assertSame(':002 DB 001 HEL 4 ' . self::OWN_NAME, $this->written[6]);
    }

    #[Test]
    public function peerHelWhileMidBarrierDoesNotOfferTwice(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));

        $count = count($this->written);
        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));

        // Only the HEL 4 ACK reply is written: the round is still in flight.
        self::assertSame([':002 DB 001 HEL 4 ACK'], array_slice($this->written, $count));
    }

    #[Test]
    public function offerIsSkippedWithoutRemoteSid(): void
    {
        $this->seedStore();
        $this->coordinator->setOwnName(self::OWN_NAME);
        $this->coordinator->onLinkReady($this->connection);
        $this->setPrivate('remoteSid', null);
        $this->setPrivate('ownHelAcked', true);
        $this->setPrivate('peerAuthorized', true);

        $this->written = [];
        $this->coordinator->onStoreInitialized();

        self::assertSame([], $this->written);
    }

    #[Test]
    public function firstHelAckWithoutAuthorizationDoesNotOffer(): void
    {
        $this->seedStore();
        $this->prepareLink();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));

        self::assertSame([], $this->written);
    }

    // ---------- Serving staged snapshots ----------

    #[Test]
    public function resServesSnapshotAndAckCompletesTheTransfer(): void
    {
        $this->makeReady();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        self::assertSame([
            ':002 DB 001 BEGIN 20 I 00000001 ' . $digest,
            ':002 DB 001 PUT 20 I 00000001 1.2.3.4::clones :*5',
            ':002 DB 001 END 20 I 00000001 ' . $digest,
        ], $this->written);
        self::assertFalse($this->coordinator->isAuthorityReady());

        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest));
        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function resIsRejectedWhileAuthorityIsNotEstablished(): void
    {
        $this->prepareLink();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        self::assertSame([':002 DB 001 ERR RES 6 20 I'], $this->written);
    }

    #[Test]
    public function resWithUnknownBlockIsIgnored(): void
    {
        $this->makeReady();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: null));

        self::assertSame([], $this->written);
    }

    #[Test]
    public function oversizedSnapshotRecordsAreRefusedWithAFatalError(): void
    {
        $snapshots = $this->createStub(UdbSnapshotProviderInterface::class);
        $snapshots->method('recordsForBlock')->willReturn(['big::path' => str_repeat('x', 5000)]);
        $coordinator = new UdbSessionCoordinator('002', $this->blockStates, $snapshots);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        $setPrivate = static function (string $prop, mixed $value) use ($coordinator): void {
            new ReflectionClass(UdbSessionCoordinator::class)->getProperty($prop)->setValue($coordinator, $value);
        };
        $setPrivate('ownHelAcked', true);
        $setPrivate('peerAuthorized', true);
        $setPrivate('storeReady', true);

        $written = [];
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        new ReflectionMethod(UdbSessionCoordinator::class, 'handleFrame')->invoke(
            $coordinator,
            new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips),
            $connection,
        );

        $digest = UdbChecksum::fromRecords([['big::path', str_repeat('x', 5000)]]);
        self::assertSame([
            ':002 DB 001 BEGIN 20 I 00000001 ' . $digest,
            ':002 DB 001 ERR PUT 3 20 I',
        ], $written);
    }

    #[Test]
    public function resServingIsSkippedWithoutRemoteSid(): void
    {
        $this->makeReady();
        $this->setPrivate('remoteSid', null);

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        self::assertSame([], $this->written);
    }

    #[Test]
    public function ackWithWrongTxidDoesNotClearTheOutstandingTransfer(): void
    {
        $this->makeReady();
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: 'wrong', checksum: '00000000'));

        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function ackWithWrongRoundDoesNotClearTheOutstandingTransfer(): void
    {
        $this->makeReady();
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 99, block: UdbBlock::Ips, txid: '00000001', checksum: $digest));

        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function ackWithWrongDigestDoesNotClearTheOutstandingTransfer(): void
    {
        $this->makeReady();
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: 'BBBBBBBB'));

        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function ackForUnknownTransferIsIgnored(): void
    {
        $this->makeReady();

        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 10, block: UdbBlock::Ips, txid: 'tx1'));

        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function ackWithNullBlockIsIgnored(): void
    {
        $this->makeReady();

        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 10, block: null, txid: 'tx1'));

        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    // ---------- Inbound traffic rejection ----------

    #[Test]
    public function inboundStagedSnapshotsAreRejectedAsForbidden(): void
    {
        $this->makeReady();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 10, block: UdbBlock::Ips, txid: 'tx1', checksum: 'AAAA1111'));
        $this->handle(new UdbFrame(UdbFrameKind::Put, '001', '002', roundId: 10, block: UdbBlock::Ips, txid: 'tx1', path: 'p', value: 'v'));
        $this->handle(new UdbFrame(UdbFrameKind::End, '001', '002', roundId: 10, block: UdbBlock::Ips, txid: 'tx1', checksum: 'AAAA1111'));
        $this->handle(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 10, block: null, txid: 'tx1', checksum: 'AAAA1111'));

        self::assertSame([
            ':002 DB 001 ERR BEGIN 6 10 I',
            ':002 DB 001 ERR PUT 6 10 I',
            ':002 DB 001 ERR END 6 10 I',
            ':002 DB 001 ERR BEGIN 6 10 0',
        ], $this->written);
    }

    #[Test]
    public function peerInfIsIgnored(): void
    {
        $this->makeReady();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Inf, '001', '002', roundId: 10, block: UdbBlock::Nicks, checksum: 'AAAA1111', timestamp: 1));
        $this->handle(new UdbFrame(UdbFrameKind::Inf, '001', '002', roundId: 10, block: null, checksum: 'AAAA1111', timestamp: 1));

        self::assertSame([], $this->written);
    }

    #[Test]
    public function forbiddenMutationsFromPeerAreRejected(): void
    {
        $this->makeReady();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Ins, '001', '002', path: 'S::nickserv', value: 'mask'));
        $this->handle(new UdbFrame(UdbFrameKind::Del, '001', '002', path: 'K::G::x'));
        $this->handle(new UdbFrame(UdbFrameKind::Del, '001', '002', path: 'K::Z::5.6.7.8'));

        self::assertSame([
            ':002 DB 001 ERR INS 6 1 S',
            ':002 DB 001 ERR DEL 6 2 K',
            ':002 DB 001 ERR DEL 6 3 K',
        ], $this->written);
    }

    // ---------- Error handling and retries ----------

    #[Test]
    public function peerErrForActiveRoundReoffersReconciliation(): void
    {
        $this->makeReady();
        $roundId = $this->activeRoundId();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: $roundId, block: UdbBlock::Ips, subcommand: 'PUT', errorCode: 3));

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->written[0]);
    }

    #[Test]
    public function onlyTheFirstErrOfARoundTriggersAReoffer(): void
    {
        $this->makeReady();
        $roundId = $this->activeRoundId();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: $roundId, block: UdbBlock::Ips, subcommand: 'PUT', errorCode: 2));
        $this->written = [];
        // Remaining ERR frames of the same (already consumed) round.
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: $roundId, block: UdbBlock::Ips, subcommand: 'PUT', errorCode: 5));
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: $roundId, block: UdbBlock::Ips, subcommand: 'END', errorCode: 5));

        self::assertSame([], $this->written);
    }

    #[Test]
    public function errReofferBudgetStopsTheOfferServeRejectLoop(): void
    {
        $this->makeReady();

        // Three consecutive ERR-triggered rounds are allowed; the fourth
        // would mean persistently rejected data and must stop the loop.
        for ($i = 0; 3 > $i; ++$i) {
            $this->written = [];
            $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: $this->activeRoundId(), block: UdbBlock::Ips, subcommand: 'PUT', errorCode: 2));
            self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->written[0] ?? '');

            // The new round fails the same way (serve + peer rejection).
            $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: $this->activeRoundId(), block: UdbBlock::Ips));
        }

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: $this->activeRoundId(), block: UdbBlock::Ips, subcommand: 'PUT', errorCode: 2));

        self::assertSame([], $this->written);
    }

    #[Test]
    public function successfulStagedAckResetsTheReofferBudget(): void
    {
        $this->makeReady();
        for ($i = 0; 3 > $i; ++$i) {
            $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: $this->activeRoundId(), block: UdbBlock::Ips, subcommand: 'PUT', errorCode: 2));
            $this->written = [];
        }

        // A confirmed transfer resets the budget: the next round can be
        // served and acked again.
        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));
        preg_match('/:002 DB 001 BEGIN 20 I (\S+) /', $this->written[0], $matches);
        // The three re-offer barrier HELs are confirmed in TCP order.
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));
        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: $matches[1], checksum: $digest));
        self::assertTrue($this->coordinator->isAuthorityReady());

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: $this->activeRoundId(), block: UdbBlock::Ips, subcommand: 'PUT', errorCode: 2));

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->written[0] ?? '');
    }

    #[Test]
    public function peerErrForStaleRoundIsIgnored(): void
    {
        $this->makeReady();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: $this->activeRoundId() + 500, block: UdbBlock::Nicks, subcommand: 'PUT', errorCode: 3));

        self::assertSame([], $this->written);
    }

    #[Test]
    public function peerErrForUnrelatedSubcommandIsIgnored(): void
    {
        $this->makeReady();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: 20, block: UdbBlock::Ips, subcommand: 'INS', errorCode: 6));

        self::assertSame([], $this->written);
    }

    #[Test]
    public function tickReoffersWhenTheBarrierAckTimesOut(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));

        $this->setPrivate('barrierDeadline', time() - 1);
        $this->written = [];
        $this->coordinator->tick($this->connection);

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->written[0]);
    }

    #[Test]
    public function tickDoesNotReofferOnceTheBarrierIsComplete(): void
    {
        $this->makeReady();

        $this->written = [];
        $this->coordinator->tick($this->connection);

        self::assertSame([], $this->written);
    }

    #[Test]
    public function tickExpiresStaleOutstandingTransfersByReoffering(): void
    {
        $this->makeReady();
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $this->expireOutstanding();
        $this->written = [];
        $this->coordinator->tick($this->connection);

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->written[0]);
        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    // ---------- Mutations ----------

    #[Test]
    public function queuedMutationsAreFlushedOnceAuthorityIsReady(): void
    {
        $this->seedStore();
        $this->prepareLink();

        $this->coordinator->enqueueMutation(new UdbMutation('S', 'nickserv', 'NickServ!NickServ@services'));
        $this->coordinator->enqueueMutation(new UdbMutation('S', 'nickserv', null));

        self::assertNotContains(':002 DB * INS S::nickserv :NickServ!NickServ@services', $this->written);

        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));

        self::assertContains(':002 DB * INS S::nickserv :NickServ!NickServ@services', $this->written);
        self::assertContains(':002 DB * DEL S::nickserv', $this->written);
    }

    #[Test]
    public function mutationQueueOverflowDropsTheOldestEntry(): void
    {
        $this->prepareLink();

        for ($i = 0; 1025 > $i; ++$i) {
            $this->coordinator->enqueueMutation(new UdbMutation('N', 'nick' . $i, 'v'));
        }

        $queue = new ReflectionClass(UdbSessionCoordinator::class)->getProperty('mutationQueue')->getValue($this->coordinator);
        self::assertCount(1024, $queue);
        self::assertSame('nick1', $queue[0]->encodedPath);
        self::assertSame('nick1024', $queue[1023]->encodedPath);
    }

    #[Test]
    public function mutationQueueOverflowWithAnAuthorizedLinkTriggersReconciliation(): void
    {
        $this->makeReady();
        // Mid-transfer: outstanding blocks the flush path, so mutations queue.
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $this->written = [];
        for ($i = 0; 1025 > $i; ++$i) {
            $this->coordinator->enqueueMutation(new UdbMutation('N', 'nick' . $i, 'v'));
        }

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->written[0]);
    }

    // ---------- Lifecycle ----------

    #[Test]
    public function resetClearsVolatileStateAndKeepsTheQueue(): void
    {
        $this->makeReady();
        $this->coordinator->enqueueMutation(new UdbMutation('S', 'nickserv', 'mask'));

        $this->coordinator->reset();

        self::assertFalse($this->coordinator->isAuthorityReady());

        // After reset the link starts over: HEL is announced again.
        $this->written = [];
        $this->coordinator->setOwnName(self::OWN_NAME);
        $this->coordinator->onRemoteServer('001', 'ircd.example.net');
        $this->coordinator->onLinkReady($this->connection);
        self::assertSame([':002 DB 001 HEL 4 ' . self::OWN_NAME], $this->written);
    }

    #[Test]
    public function framesWithoutConnectionAreDroppedWithAWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $coordinator = new UdbSessionCoordinator('002', $this->blockStates, $this->snapshots, $logger);
        new ReflectionMethod(UdbSessionCoordinator::class, 'write')->invoke($coordinator, ':002 DB 001 HEL 4 x');
    }

    #[Test]
    public function storeReadyIsRecalculatedAfterReset(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));

        $this->coordinator->reset();

        // storeReady cache was invalidated; the store is still ready but the
        // handshake state was cleared.
        $this->coordinator->setOwnName(self::OWN_NAME);
        $this->coordinator->onRemoteServer('001', 'ircd.example.net');
        $this->coordinator->onLinkReady($this->connection);
        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));

        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function peerHelBeforeOurOwnAnnouncementTriggersOurHel(): void
    {
        $this->seedStore();
        $this->coordinator->setOwnName(self::OWN_NAME);
        $this->coordinator->onRemoteServer('001', 'ircd.example.net');

        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));

        self::assertSame([
            ':002 DB 001 HEL 4 ACK',
            ':002 DB 001 HEL 4 ' . self::OWN_NAME,
        ], $this->written);
    }

    #[Test]
    public function peerHelWhileTransfersAreOutstandingDoesNotOffer(): void
    {
        $this->makeReady();
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));

        self::assertSame([':002 DB 001 HEL 4 ACK'], $this->written);
    }

    #[Test]
    public function flushWithoutAConnectionIsANoOp(): void
    {
        $this->makeReady();
        $this->coordinator->enqueueMutation(new UdbMutation('S', 'nickserv', 'mask'));
        $this->setPrivate('connection', null);

        new ReflectionMethod(UdbSessionCoordinator::class, 'flushMutations')->invoke($this->coordinator);

        $queue = new ReflectionClass(UdbSessionCoordinator::class)->getProperty('mutationQueue')->getValue($this->coordinator);
        self::assertCount(1, $queue);
    }

    // ---------- Helpers ----------

    private function setPrivate(string $property, mixed $value): void
    {
        new ReflectionClass(UdbSessionCoordinator::class)->getProperty($property)->setValue($this->coordinator, $value);
    }

    private function handle(UdbFrame $frame): void
    {
        $this->coordinator->handleFrame($frame, $this->connection);
    }

    private function prepareLink(): void
    {
        $this->coordinator->setOwnName(self::OWN_NAME);
        $this->coordinator->onRemoteServer('001', 'ircd.example.net');
        $this->coordinator->onLinkReady($this->connection);
    }

    private function seedStore(): void
    {
        foreach (UdbBlock::all() as $block) {
            $this->blockStates->upsert($block->letter(), '00000000');
        }
    }

    /**
     * Full authority handshake: HEL exchange, inventory barrier completed.
     */
    private function makeReady(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));
    }

    private function activeRoundId(): int
    {
        return new ReflectionClass(UdbSessionCoordinator::class)
            ->getProperty('activeRoundId')
            ->getValue($this->coordinator);
    }

    private function expireOutstanding(): void
    {
        $property = new ReflectionClass(UdbSessionCoordinator::class)->getProperty('outstanding');
        $outstanding = $property->getValue($this->coordinator);
        foreach ($outstanding as $letter => $info) {
            $outstanding[$letter]['deadline'] = time() - 1;
        }
        $property->setValue($this->coordinator, $outstanding);
    }
}
