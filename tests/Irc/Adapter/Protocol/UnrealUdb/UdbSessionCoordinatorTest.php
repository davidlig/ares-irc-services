<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbAuthorityStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Projection\Oclg\UdbOclgView;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbSnapshotProviderInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbHelloBarrier;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbPeerSession;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionCoordinator;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutation;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbWireTakeover;
use App\Irc\Adapter\Protocol\UnrealUdb\Transfer\UdbOutboundTransferTracker;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrameKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbOclgViewDigest;
use App\Irc\Adapter\Runtime\SessionEventPump;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

use function array_slice;
use function count;

#[CoversClass(UdbSessionCoordinator::class)]
#[CoversClass(UdbPeerSession::class)]
final class UdbSessionCoordinatorTest extends TestCase
{
    private const string OWN_NAME = 'services.example.net';

    private const string PEER_EPOCH = '1111111111111111';

    private FakeBlockStates $blockStates;

    private UdbSnapshotProviderInterface $snapshots;

    /** @var list<string> */
    private array $written = [];

    private ConnectionInterface $connection;

    private UdbSessionCoordinator $coordinator;

    private MutableUdbClock $clock;

    private DeterministicLoopScheduler $scheduler;

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
        $this->clock = new MutableUdbClock();
        $this->scheduler = new DeterministicLoopScheduler();
        $this->coordinator = new UdbSessionCoordinator(
            '002',
            $this->blockStates,
            $this->snapshots,
            scheduler: $this->scheduler,
            clock: $this->clock,
        );
    }

    // ---------- Link establishment ----------

    #[Test]
    public function linkReadyAnnouncesOurselvesAsPropagator(): void
    {
        $this->prepareLink();

        self::assertSame([$this->helRequest()], $this->written);
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
    public function getRemoteServerNameReturnsCapturedRemoteName(): void
    {
        self::assertNull($this->coordinator->getRemoteServerName());

        $this->coordinator->onRemoteServer('001', 'ircd.example.net');

        self::assertSame('ircd.example.net', $this->coordinator->getRemoteServerName());
    }

    #[Test]
    public function completeWireBootstrapDoesNothingWithoutWireAuthority(): void
    {
        new ReflectionMethod(UdbSessionCoordinator::class, 'completeWireBootstrap')->invoke($this->coordinator);

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
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        self::assertSame($this->helAck(), $this->written[0]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N [0-9A-F]{8} \d+$/', $this->written[1]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ C /', $this->written[2]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ I /', $this->written[3]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ S /', $this->written[4]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ L /', $this->written[5]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ K /', $this->written[6]);
        self::assertSame($this->helRequest(), $this->written[7]);
    }

    #[Test]
    public function questionMarkPropagatorDoesNotAuthorizeUs(): void
    {
        $this->seedStore();
        $this->prepareLink();

        $this->handle($this->peerHel('?'));
        $this->handle($this->peerHelAck('?'));

        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function differentPropagatorDoesNotAuthorizeUs(): void
    {
        $this->seedStore();
        $this->prepareLink();

        $this->handle($this->peerHel('ircd.example.net'));
        $this->handle($this->peerHelAck('ircd.example.net'));

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
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        // INF x6 + barrier HEL sent, but the barrier ACK has not arrived.
        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function offerIsSkippedWhileTheStoreIsNotInitialized(): void
    {
        $this->prepareLink();

        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        // Our own HEL (from link establishment) + the HEL ACK reply: no INF
        // round without a ready store.
        self::assertSame([
            $this->helRequest(),
            $this->helAck(),
        ], $this->written);
    }

    #[Test]
    public function onStoreInitializedReevaluatesAndOffers(): void
    {
        $this->prepareLink();
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        $this->written = [];
        $this->seedStore();
        $this->coordinator->onStoreInitialized();

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->written[0]);
        self::assertSame($this->helRequest(), $this->written[6]);
    }

    #[Test]
    public function peerHelWhileMidBarrierDoesNotOfferTwice(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        $count = count($this->written);
        $this->handle($this->peerHel());

        // Only the HEL 4 ACK reply is written: the round is still in flight.
        self::assertSame([$this->helAck()], array_slice($this->written, $count));
    }

    #[Test]
    public function offerIsSkippedWithoutRemoteSid(): void
    {
        $this->seedStore();
        $this->coordinator->setOwnName(self::OWN_NAME);
        $this->coordinator->onLinkReady($this->connection);

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
        $this->handle($this->peerHelAck('-'));

        self::assertSame([], $this->written);
    }

    #[Test]
    public function malformedHelAckCannotConfirmTheSessionOrStartReconciliation(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->written = [];

        $this->handle(new UdbFrame(
            UdbFrameKind::HelAck,
            '001',
            '002',
            propagator: self::OWN_NAME,
            epoch: 'not-an-epoch',
            capabilities: ['OCL', 'OCLG'],
        ));

        self::assertSame([], $this->written);
        self::assertNull($this->coordinator->activeRoundId());
        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function malformedHelCannotAuthorizeTheSession(): void
    {
        $this->prepareLink();
        $this->written = [];

        $this->handle(new UdbFrame(
            UdbFrameKind::Hel,
            '001',
            '002',
            propagator: self::OWN_NAME,
            epoch: self::PEER_EPOCH,
            capabilities: ['OCLG'],
        ));

        self::assertSame([], $this->written);
        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function duplicateHelAckWithoutAPendingBarrierIsIgnored(): void
    {
        $this->makeReady();
        $this->written = [];

        $this->handle($this->peerHelAck());

        self::assertSame([], $this->written);
        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    // ---------- Serving staged snapshots ----------

    #[Test]
    public function resServesSnapshotAndAckCompletesTheTransfer(): void
    {
        $this->startRound();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        self::assertSame([
            ':002 DB 001 BEGIN 20 I 00000001 ' . $digest,
            ':002 DB 001 PUT 20 I 00000001 1.2.3.4::clones :*5',
            ':002 DB 001 END 20 I 00000001 ' . $digest,
        ], $this->written);
        self::assertFalse($this->coordinator->isAuthorityReady());

        $this->handle($this->peerHelAck());
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
        $this->seedStore();
        $coordinator = new UdbSessionCoordinator(
            '002',
            $this->blockStates,
            $snapshots,
            scheduler: $this->scheduler,
            clock: $this->clock,
        );
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        $written = [];
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHel(), $connection);
        $coordinator->handleFrame($this->peerHelAck(), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips), $connection);

        self::assertContains(':002 DB 001 ERR PUT 3 20 I', $written);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF 21 N /', array_slice($written, -7)[0]);
        self::assertSame(21, $coordinator->activeRoundId());
        self::assertFalse($coordinator->isAuthorityReady());
    }

    #[Test]
    public function resServingIsSkippedWithoutRemoteSid(): void
    {
        $this->makeReady();
        $this->coordinator->reset();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        self::assertSame([], $this->written);
    }

    #[Test]
    public function ackWithWrongTxidDoesNotClearTheOutstandingTransfer(): void
    {
        $this->startRound();
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: 'wrong', checksum: '00000000'));

        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function ackWithWrongRoundDoesNotClearTheOutstandingTransfer(): void
    {
        $this->startRound();
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 99, block: UdbBlock::Ips, txid: '00000001', checksum: $digest));

        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function ackWithWrongDigestDoesNotClearTheOutstandingTransfer(): void
    {
        $this->startRound();
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

    #[Test]
    public function staleAndDuplicateResCannotReplaceTheActiveTransfer(): void
    {
        $this->startRound();
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 19, block: UdbBlock::Ips));
        self::assertSame([':002 DB 001 ERR RES 5 19 I'], $this->written);

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));
        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        self::assertSame(':002 DB 001 BEGIN 20 I 00000001 ' . $digest, $this->written[0]);

        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));
        self::assertSame(':002 DB 001 ERR RES 4 20 I', $this->written[3]);
        self::assertCount(1, array_filter(
            $this->written,
            static fn (string $line): bool => str_contains($line, ' BEGIN '),
        ));

        $this->handle($this->peerHelAck());
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest));
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
    public function stagedFramesAndMutationsFromANonDirectPeerAreIgnored(): void
    {
        $this->makeReady();
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::Begin, '999', '002', roundId: 10, block: UdbBlock::Ips, txid: 'tx1', checksum: 'AAAA1111'));
        $this->handle(new UdbFrame(UdbFrameKind::Ins, '999', '002', path: 'S::nickserv', value: 'mask'));

        self::assertSame([], $this->written);
        self::assertTrue($this->coordinator->isAuthorityReady());
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
    public function oclgFramesFromNonDirectPeersAreIgnored(): void
    {
        $entries = ['netadmin' => str_repeat('a', 64)];

        // Without prepareLink() the coordinator has no remote SID, so every
        // OCLG frame comes from a non-direct peer and must be dropped.
        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 7, epoch: '0123456789abcdef', status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 7, epoch: '0123456789abcdef', path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 7, epoch: '0123456789abcdef'));

        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function invalidOclgBeginDescriptorAbortsTheStage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $this->coordinator = new UdbSessionCoordinator('002', $this->blockStates, $this->snapshots, $logger, new UdbOclgView($logger));
        $this->prepareLink();
        $this->handle($this->peerHel());

        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 7, epoch: self::PEER_EPOCH, status: 'BROKEN', count: 1, checksum: str_repeat('a', 64)));

        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function duplicateOclgItemAbortsTheStage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $this->coordinator = new UdbSessionCoordinator('002', $this->blockStates, $this->snapshots, $logger, new UdbOclgView($logger));
        $this->prepareLink();
        $this->handle($this->peerHel());

        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 7, epoch: self::PEER_EPOCH, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 7, epoch: self::PEER_EPOCH, path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 7, epoch: self::PEER_EPOCH, path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 7, epoch: self::PEER_EPOCH));

        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function oclgEndForAnotherGenerationIsIgnored(): void
    {
        $this->prepareLink();
        $this->handle($this->peerHel());

        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 7, epoch: self::PEER_EPOCH, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 7, epoch: self::PEER_EPOCH, path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 99, epoch: self::PEER_EPOCH));

        // The stage is still pending: nothing was committed or withdrawn.
        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function oclgReadySnapshotIsCommittedAtomically(): void
    {
        $this->prepareLink();
        $this->handle($this->peerHel());
        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 7, epoch: self::PEER_EPOCH, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));

        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 7, epoch: self::PEER_EPOCH, path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 7, epoch: self::PEER_EPOCH));

        self::assertTrue($this->coordinator->isOperclassGloballyAvailable('netadmin'));
        self::assertSame(['netadmin'], $this->coordinator->getAvailableOperclasses());
    }

    #[Test]
    public function invalidOrIncompleteOclgSnapshotsWithdrawAvailability(): void
    {
        $this->prepareLink();
        $this->handle($this->peerHel());
        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 7, epoch: self::PEER_EPOCH, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 7, epoch: self::PEER_EPOCH, path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 7, epoch: self::PEER_EPOCH));
        self::assertTrue($this->coordinator->isOperclassGloballyAvailable('netadmin'));
        self::assertSame(['netadmin'], $this->coordinator->getAvailableOperclasses());

        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 8, epoch: self::PEER_EPOCH, status: 'INCOMPLETE', count: 0, checksum: UdbOclgViewDigest::fromEntries(false, [])));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 8, epoch: self::PEER_EPOCH));
        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));
        self::assertSame([], $this->coordinator->getAvailableOperclasses());

        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 9, epoch: self::PEER_EPOCH, status: 'READY', count: 1, checksum: str_repeat('b', 64)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 9, epoch: self::PEER_EPOCH, path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 9, epoch: self::PEER_EPOCH));
        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));
        self::assertSame([], $this->coordinator->getAvailableOperclasses());
    }

    #[Test]
    public function coordinatorExpiresAnIncompleteOclgStageDeterministically(): void
    {
        $view = new UdbOclgView(clock: $this->clock);
        $this->coordinator = new UdbSessionCoordinator(
            '002',
            $this->blockStates,
            $this->snapshots,
            oclgView: $view,
            scheduler: $this->scheduler,
            clock: $this->clock,
        );
        $this->prepareLink();
        $this->handle($this->peerHel());

        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->handle(new UdbFrame(
            UdbFrameKind::OclgBegin,
            '001',
            '002',
            roundId: 7,
            epoch: self::PEER_EPOCH,
            status: 'READY',
            count: 1,
            checksum: UdbOclgViewDigest::fromEntries(true, $entries),
        ));
        self::assertNotNull($view->nextDeadline());

        $this->clock->advance(30);
        $this->coordinator->tick($this->connection);

        self::assertNull($view->nextDeadline());
        self::assertSame([], $this->coordinator->getAvailableOperclasses());
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
        $this->startRound();
        $roundId = $this->activeRoundId();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: $roundId, block: UdbBlock::Ips, subcommand: 'PUT', errorCode: 3));

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->written[0]);
    }

    #[Test]
    public function onlyTheFirstErrOfARoundTriggersAReoffer(): void
    {
        $this->startRound();
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
        $this->startRound();

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
        $this->startRound();
        for ($i = 0; 3 > $i; ++$i) {
            $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: $this->activeRoundId(), block: UdbBlock::Ips, subcommand: 'PUT', errorCode: 2));
            $this->written = [];
        }

        // A confirmed transfer resets the budget: the next round can be
        // served and acked again.
        $this->written = [];
        $roundId = $this->activeRoundId();
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: $roundId, block: UdbBlock::Ips));
        $txid = $this->extractTxid($this->firstWrittenLine());
        // The abandoned initial barrier plus three re-offer barriers are
        // confirmed in TCP order; only the last belongs to the current round.
        $this->handle($this->peerHelAck());
        $this->handle($this->peerHelAck());
        $this->handle($this->peerHelAck());
        $this->handle($this->peerHelAck());
        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: $roundId, block: UdbBlock::Ips, txid: $txid, checksum: $digest));
        self::assertTrue($this->coordinator->isAuthorityReady());

        $this->handle($this->peerHel());
        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: $this->activeRoundId(), block: UdbBlock::Ips, subcommand: 'PUT', errorCode: 2));

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->written[0] ?? '');
    }

    #[Test]
    public function peerErrForStaleRoundIsIgnored(): void
    {
        $this->startRound();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: $this->activeRoundId() + 500, block: UdbBlock::Nicks, subcommand: 'PUT', errorCode: 3));

        self::assertSame([], $this->written);
    }

    #[Test]
    public function peerErrForUnrelatedSubcommandIsIgnored(): void
    {
        $this->startRound();

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: 20, block: UdbBlock::Ips, subcommand: 'INS', errorCode: 6));

        self::assertSame([], $this->written);
    }

    #[Test]
    public function tickReoffersWhenTheBarrierAckTimesOut(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        $this->clock->advance(60);
        $this->written = [];
        $this->coordinator->tick($this->connection);

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->firstWrittenLine());
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
        $this->startRound();
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $this->expireOutstanding();
        $this->written = [];
        $this->coordinator->tick($this->connection);

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->firstWrittenLine());
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

        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());
        $this->handle($this->peerHelAck());

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

        self::assertSame(1024, $this->coordinator->queuedMutationCount());

        $this->seedStore();
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());
        $this->handle($this->peerHelAck());
        $lines = implode("\n", $this->written);
        self::assertStringNotContainsString('N::nick0 ', $lines);
        self::assertStringContainsString('N::nick1 ', $lines);
        self::assertStringContainsString('N::nick1024 ', $lines);
    }

    #[Test]
    public function mutationQueueOverflowWithAnAuthorizedLinkTriggersReconciliation(): void
    {
        $this->startRound();
        // Mid-transfer: outstanding blocks the flush path, so mutations queue.
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $this->written = [];
        for ($i = 0; 1025 > $i; ++$i) {
            $this->coordinator->enqueueMutation(new UdbMutation('N', 'nick' . $i, 'v'));
        }

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->firstWrittenLine());
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
        self::assertSame([$this->helRequest()], $this->written);
    }

    #[Test]
    public function remoteEpochChangeInvalidatesReadinessAndRequiresANewBarrier(): void
    {
        $this->makeReady();
        self::assertTrue($this->coordinator->isAuthorityReady());
        $this->written = [];

        $newEpoch = '2222222222222222';
        $this->handle($this->peerHel(epoch: $newEpoch));

        self::assertSame([$this->helAck(), $this->helRequest()], $this->written);
        self::assertFalse($this->coordinator->isAuthorityReady());
        self::assertNull($this->coordinator->activeRoundId());

        $this->written = [];
        $this->handle($this->peerHelAck(epoch: $newEpoch));
        self::assertSame(21, $this->coordinator->activeRoundId());
        self::assertFalse($this->coordinator->isAuthorityReady());

        $this->handle($this->peerHelAck(epoch: $newEpoch));
        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function epochChangeFirstObservedInHelAckRestartsNegotiation(): void
    {
        $this->makeReady();
        $this->written = [];

        $this->handle($this->peerHelAck(epoch: '2222222222222222'));

        self::assertSame([$this->helRequest()], $this->written);
        self::assertNull($this->coordinator->activeRoundId());
        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function stateCollaboratorsFailClosedWhenCoordinatorPrerequisitesDisappear(): void
    {
        $peer = new UdbPeerSession();
        $peer->setOwnName(self::OWN_NAME);
        $peer->captureRemote('001', 'ircd.example.net');
        $peer->observeAdvertisement($this->peerHel(), false);
        self::assertSame(self::PEER_EPOCH, $peer->remoteEpoch());
        $barrier = new UdbHelloBarrier();
        $barrier->acknowledge();
        $barrier->sent(20, 60);
        $barrier->acknowledge();
        $transfers = new UdbOutboundTransferTracker();
        $transfers->track(UdbBlock::Ips, 20, 'occupied', UdbChecksum::EMPTY, 20);
        $this->seedStore();

        $coordinator = new UdbSessionCoordinator(
            '002',
            $this->blockStates,
            $this->snapshots,
            scheduler: $this->scheduler,
            clock: $this->clock,
            peer: $peer,
            helloBarrier: $barrier,
            transfers: $transfers,
        );

        new ReflectionMethod(UdbSessionCoordinator::class, 'offerReconciliation')->invoke($coordinator);
        new ReflectionMethod(UdbSessionCoordinator::class, 'serveBlock')->invoke($coordinator, UdbBlock::Ips, 20);
        new ReflectionMethod(UdbSessionCoordinator::class, 'flushMutations')->invoke($coordinator);
        $peer->reset();
        new ReflectionMethod(UdbSessionCoordinator::class, 'serveBlock')->invoke($coordinator, UdbBlock::Ips, 20);

        self::assertNull($coordinator->activeRoundId());
        self::assertFalse($coordinator->isAuthorityReady());
    }

    #[Test]
    public function unacknowledgedInitialHelDisconnectsAndResetsWithoutSleeping(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('disconnect');
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });
        $this->connection = $connection;
        $this->prepareLink();

        $this->clock->advance(60);
        $this->coordinator->tick($connection);

        self::assertNull($this->coordinator->getRemoteServerName());
        self::assertNull($this->coordinator->activeRoundId());
        self::assertFalse($this->coordinator->isAuthorityReady());
        self::assertNull($this->coordinator->getDeadlineWatcherId());
    }

    #[Test]
    public function reconnectUsesANewerRoundAndRejectsThePreviousTransferAck(): void
    {
        $this->startRound();
        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $this->coordinator->reset();
        $this->prepareLink();
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());
        self::assertSame(21, $this->coordinator->activeRoundId());

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 21, block: UdbBlock::Ips));
        self::assertSame(':002 DB 001 BEGIN 21 I 00000002 ' . $digest, $this->written[0]);
        $this->handle($this->peerHelAck());
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest));
        self::assertFalse($this->coordinator->isAuthorityReady());

        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 21, block: UdbBlock::Ips, txid: '00000002', checksum: $digest));
        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function ackErrAndBarrierFromANonDirectPeerCannotAdvanceOrAbortTheRound(): void
    {
        $this->startRound();
        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));
        $this->written = [];

        $this->handle($this->peerHelAck(sourceSid: '999'));
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '999', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest));
        $this->handle(new UdbFrame(UdbFrameKind::Err, '999', '002', roundId: 20, block: UdbBlock::Ips, subcommand: 'PUT', errorCode: 3));

        self::assertSame([], $this->written);
        self::assertSame(20, $this->coordinator->activeRoundId());
        self::assertFalse($this->coordinator->isAuthorityReady());

        $this->handle($this->peerHelAck());
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest));
        self::assertTrue($this->coordinator->isAuthorityReady());
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
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        $this->coordinator->reset();

        // storeReady cache was invalidated; the store is still ready but the
        // handshake state was cleared.
        $this->coordinator->setOwnName(self::OWN_NAME);
        $this->coordinator->onRemoteServer('001', 'ircd.example.net');
        $this->coordinator->onLinkReady($this->connection);
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());
        $this->handle($this->peerHelAck());

        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function peerHelBeforeOurOwnAnnouncementTriggersOurHel(): void
    {
        $this->seedStore();
        $this->coordinator->setOwnName(self::OWN_NAME);
        $this->coordinator->onRemoteServer('001', 'ircd.example.net');

        $this->handle($this->peerHel());

        self::assertSame([
            $this->helAck(),
            $this->helRequest(),
        ], $this->written);
    }

    #[Test]
    public function peerHelWhileTransfersAreOutstandingDoesNotOffer(): void
    {
        $this->startRound();
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $this->written = [];
        $this->handle($this->peerHel());

        self::assertSame([$this->helAck()], $this->written);
    }

    #[Test]
    public function helFromAnUnexpectedPeerCannotAuthorizeUsOrReceiveAnAck(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->written = [];

        $this->handle($this->peerHel(sourceSid: '999'));
        $this->handle($this->peerHel(target: '999'));
        $this->handle($this->peerHelAck(sourceSid: '999'));

        self::assertSame([], $this->written);
        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function flushWithoutAConnectionIsANoOp(): void
    {
        $this->makeReady();
        $this->coordinator->enqueueMutation(new UdbMutation('S', 'nickserv', 'mask'));
        $this->coordinator->reset();

        self::assertSame(1, $this->coordinator->queuedMutationCount());
    }

    // ---------- Wire bootstrap ----------

    #[Test]
    public function wireBootstrapAnnouncesTheQuestionMarkPropagator(): void
    {
        $takeover = $this->createWireTakeover();
        $coordinator = $this->bootstrapCoordinator($takeover);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);

        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 \? [0-9a-f]{16} OCL OCLG$/', $this->written[0]);
    }

    #[Test]
    public function wireBootstrapImportsStagedTransfersAndApprovesTheAuthority(): void
    {
        $takeover = $this->createWireTakeover();
        $coordinator = $this->bootstrapCoordinator($takeover);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHelAck(), $connection);
        $this->written = [];

        // The peer first advertises the inventory. Services request the
        // divergent block with RES before accepting BEGIN/PUT/END.
        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Inf, '001', '002', roundId: 1, block: UdbBlock::Ips, checksum: $digest, timestamp: 1), $connection);
        self::assertSame(':002 DB 001 RES 1 I', $this->firstWrittenLine());
        $this->written = [];
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: $digest), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Put, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', path: '1.2.3.4::clones', value: '*5'), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::End, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: $digest), $connection);

        self::assertMatchesRegularExpression('/^:002 DB 001 ACK 1 I tx1 ' . $digest . '$/', $this->firstWrittenLine());
    }

    #[Test]
    public function wireBootstrapIgnoresInventoryUntilTheHelloBarrierIsConfirmed(): void
    {
        $takeover = $this->createWireTakeover();
        $coordinator = $this->bootstrapCoordinator($takeover);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $this->written = [];

        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Inf, '001', '002', roundId: 1, block: UdbBlock::Ips, checksum: UdbChecksum::EMPTY, timestamp: 1), $connection);

        self::assertSame([], $this->written);
        self::assertSame([], $takeover->completedBlocks());
    }

    #[Test]
    public function wireBootstrapRejectsBeginUntilThatBlockWasRequestedFromAnInventory(): void
    {
        $takeover = $this->createWireTakeover();
        $coordinator = $this->bootstrapCoordinator($takeover);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHelAck(), $connection);
        $this->written = [];

        $coordinator->handleFrame(new UdbFrame(
            UdbFrameKind::Begin,
            '001',
            '002',
            roundId: 7,
            block: UdbBlock::Ips,
            txid: 'tx1',
            checksum: UdbChecksum::EMPTY,
        ), $connection);

        self::assertSame([':002 DB 001 ERR BEGIN 5 7 I'], $this->written);
        self::assertSame([], $takeover->completedBlocks());
    }

    #[Test]
    public function wireBootstrapRejectsInventoriesFromAnotherRoundWithoutMixingThem(): void
    {
        $takeover = $this->createWireTakeover();
        $coordinator = $this->bootstrapCoordinator($takeover);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHelAck(), $connection);
        $this->written = [];

        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Inf, '001', '002', roundId: 7, block: UdbBlock::Ips, checksum: UdbChecksum::EMPTY, timestamp: 1), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Inf, '001', '002', roundId: 8, block: UdbBlock::Settings, checksum: UdbChecksum::EMPTY, timestamp: 1), $connection);

        self::assertSame([
            ':002 DB 001 RES 7 I',
            ':002 DB 001 ERR INF 5 8 S',
        ], $this->written);
        self::assertSame([], $takeover->completedBlocks());
    }

    #[Test]
    public function unapprovedBootstrapNeverOffersTheLocalStoreOrBecomesReady(): void
    {
        $this->seedStore();
        $takeover = $this->createWireTakeover();
        $coordinator = $this->bootstrapCoordinator($takeover);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $connection = $this->captureConnection($coordinator);

        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHel(), $connection);
        $coordinator->handleFrame($this->peerHelAck(), $connection);

        self::assertStringNotContainsString(' INF ', implode("\n", $this->written));
        self::assertFalse($coordinator->isAuthorityReady());
    }

    #[Test]
    public function wireBootstrapCannotBeFedOrCompletedByANonDirectPeer(): void
    {
        $takeover = $this->createWireTakeover();
        $coordinator = $this->bootstrapCoordinator($takeover);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHelAck(), $connection);
        $this->written = [];

        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Inf, '999', '002', roundId: 1, block: UdbBlock::Ips, checksum: $digest, timestamp: 1), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Begin, '999', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: $digest), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Put, '999', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', path: '1.2.3.4::clones', value: '*5'), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::End, '999', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: $digest), $connection);

        self::assertSame([], $this->written);
        self::assertSame([], $takeover->completedBlocks());
        self::assertFalse($takeover->isComplete());
    }

    #[Test]
    public function coordinatorExpiresWireTakeoverStageDeterministically(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $takeover = $this->createWireTakeover($logger);
        $coordinator = $this->bootstrapCoordinator($takeover, $logger);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHelAck(), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Inf, '001', '002', roundId: 1, block: UdbBlock::Ips, checksum: UdbChecksum::EMPTY, timestamp: 1), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: UdbChecksum::EMPTY), $connection);

        $this->clock->advance(60);
        $this->written = [];
        $coordinator->tick($connection);

        self::assertNull($takeover->nextDeadline());
        self::assertFalse($takeover->isComplete());
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 \? [0-9a-f]{16} OCL OCLG$/', $this->firstWrittenLine());
    }

    #[Test]
    public function approvedAuthorityRejectsStagedTrafficAgain(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $takeover = $this->createWireTakeover($logger);
        $authority = $this->createStub(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(true);
        $coordinator = $this->bootstrapCoordinator($takeover, $logger, $authority);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        $connection = $this->captureConnection($coordinator);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: str_repeat('0', 8)), $connection);

        self::assertSame([':002 DB 001 ERR BEGIN 6 1 I'], $this->written);
    }

    #[Test]
    public function wireBootstrapCompletesApprovesAndRenegotiatesAsFqdnAuthority(): void
    {
        $records = new FakeUdbRecords();
        $states = new FakeBlockStates();
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static function (callable $callback): void {
            $callback();
        });
        $em->method('clear');
        $takeover = new UdbWireTakeover($records, $states, $em, $this->createEmptyExporter());

        $authority = $this->createMock(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(false);
        $authority->expects(self::once())->method('approve');

        // The coordinator's block-state repository IS the takeover's: after
        // finalize() the store becomes ready without a new handshake.
        $coordinator = new UdbSessionCoordinator(
            '002',
            $states,
            $this->snapshots,
            new NullLogger(),
            new UdbOclgView(),
            $takeover,
            $authority,
            $this->scheduler,
            $this->clock,
        );
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHelAck(), $connection);
        // The peer announces itself (its HEL authorizes the bootstrap exchange).
        $coordinator->handleFrame($this->peerHel(), $connection);
        $this->written = [];

        // Stage every block over the wire (SQL-owned blocks are discarded).
        foreach ($this->bootstrapRecords() as [$block, $path, $value]) {
            $digest = UdbChecksum::fromRecords([[$path, $value]]);
            $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Inf, '001', '002', roundId: 1, block: $block, checksum: $digest, timestamp: 1), $connection);
            $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1, block: $block, txid: 'tx', checksum: $digest), $connection);
            $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Put, '001', '002', roundId: 1, block: $block, txid: 'tx', path: $path, value: $value), $connection);
            $coordinator->handleFrame(new UdbFrame(UdbFrameKind::End, '001', '002', roundId: 1, block: $block, txid: 'tx', checksum: $digest), $connection);
        }

        // The last block completed the bootstrap: authority approved, FQDN
        // HEL renegotiation and the reconciliation round are on the wire.
        $lines = implode("\n", $this->written);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 services\.example\.net [0-9a-f]{16} OCL OCLG$/m', $lines);
        self::assertStringNotContainsString(':002 DB 001 INF', $lines);

        $coordinator->handleFrame($this->peerHelAck(), $connection);
        $lines = implode("\n", $this->written);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N [0-9A-F]{8} \d+$/m', $lines);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ K /m', $lines);
    }

    #[Test]
    public function wireBootstrapFinalizationFailureKeepsTheStoreUnapproved(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error');
        $takeover = $this->createWireTakeover();
        $authority = $this->createStub(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(false);
        $authority->method('approve')->willThrowException(new RuntimeException('Database down.'));
        $coordinator = $this->bootstrapCoordinator($takeover, $logger, $authority);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHelAck(), $connection);
        $this->written = [];

        // Complete a full six-block exchange with a broken authority store.
        foreach ($this->bootstrapRecords() as [$block, $path, $value]) {
            $digest = UdbChecksum::fromRecords([[$path, $value]]);
            $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Inf, '001', '002', roundId: 1, block: $block, checksum: $digest, timestamp: 1), $connection);
            $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1, block: $block, txid: 'tx', checksum: $digest), $connection);
            $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Put, '001', '002', roundId: 1, block: $block, txid: 'tx', path: $path, value: $value), $connection);
            $coordinator->handleFrame(new UdbFrame(UdbFrameKind::End, '001', '002', roundId: 1, block: $block, txid: 'tx', checksum: $digest), $connection);
        }

        // The failed approval left the wire bootstrap active: no FQDN HEL
        // renegotiation and no reconciliation round were sent.
        self::assertNotContains(':002 DB 001 INF', $this->written);
    }

    #[Test]
    public function wireBootstrapWithoutAnAuthorityRejectsStagedTrafficFailClosed(): void
    {
        // No authority repository: the wire bootstrap is not active and the
        // staged traffic is rejected (fail-closed), never crash.
        $coordinator = new UdbSessionCoordinator('002', $this->blockStates, $this->snapshots, new NullLogger(), new UdbOclgView(), $this->createWireTakeover());
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $this->written = [];

        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: $digest), $connection);

        self::assertSame([':002 DB 001 ERR BEGIN 6 1 I'], $this->written);
    }

    #[Test]
    public function approvedAuthorityAdvertisesOwnNameInsteadOfQuestionMark(): void
    {
        $authority = $this->createStub(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(true);

        $takeover = $this->createWireTakeover();
        $coordinator = new UdbSessionCoordinator(
            '002',
            $this->blockStates,
            $this->snapshots,
            new NullLogger(),
            new UdbOclgView(),
            $takeover,
            $authority,
        );
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        // 1. Initial HEL announces own name FQDN, never '?'
        $coordinator->onLinkReady($connection);
        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 ' . preg_quote(self::OWN_NAME, '/') . ' [0-9a-f]{16} OCL OCLG$/', $this->written[0]);
        $this->written = [];

        // 2. Peer sends HEL with '-': does not authorize us when store is approved
        $coordinator->handleFrame($this->peerHel('-'), $connection);
        self::assertNotEmpty($this->written);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 ACK ' . preg_quote(self::OWN_NAME, '/') . ' [0-9a-f]{16} OCL OCLG$/', $this->firstWrittenLine());
        $this->written = [];

        // 3. Peer sends HEL selecting our FQDN: authorizes us, HEL ACK announces our FQDN
        $coordinator->handleFrame($this->peerHel(), $connection);
        self::assertNotEmpty($this->written);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 ACK ' . preg_quote(self::OWN_NAME, '/') . ' [0-9a-f]{16} OCL OCLG$/', $this->firstWrittenLine());
        $this->written = [];

        // Seed store so reconciliation can proceed
        foreach (UdbBlock::all() as $block) {
            $this->blockStates->upsert($block->letter(), '00000000');
        }

        // 4. Peer ACKs our HEL: reconciliation round starts, TCP barrier HEL announces our FQDN (never '?')
        $coordinator->handleFrame($this->peerHelAck(), $connection);
        $lines = implode("\n", $this->written);
        self::assertStringContainsString(':002 DB 001 INF', $lines);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 ' . preg_quote(self::OWN_NAME, '/') . ' [0-9a-f]{16} OCL OCLG$/m', $lines);
        self::assertStringNotContainsString('HEL 4 ?', $lines);
        self::assertStringNotContainsString('HEL 4 ACK ?', $lines);
    }

    #[Test]
    public function resetClearsApprovedCacheSoReconnectionReevaluatesAuthority(): void
    {
        $authority = $this->createMock(UdbAuthorityStateRepositoryInterface::class);
        $authority->expects(self::exactly(2))->method('isApproved')->willReturnOnConsecutiveCalls(false, true);

        $takeover = $this->createWireTakeover();
        $coordinator = new UdbSessionCoordinator(
            '002',
            $this->blockStates,
            $this->snapshots,
            new NullLogger(),
            new UdbOclgView(),
            $takeover,
            $authority,
        );
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        // First link: not approved yet -> announces '?'
        $coordinator->onLinkReady($connection);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 \? [0-9a-f]{16} OCL OCLG$/', $this->firstWrittenLine());

        $this->written = [];
        $coordinator->reset();
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        // Second link: reset cleared cache, authority now returns true -> announces FQDN
        $coordinator->onLinkReady($connection);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 ' . preg_quote(self::OWN_NAME, '/') . ' [0-9a-f]{16} OCL OCLG$/', $this->firstWrittenLine());
    }

    private function bootstrapCoordinator(
        UdbWireTakeover $takeover,
        ?LoggerInterface $logger = null,
        ?UdbAuthorityStateRepositoryInterface $authority = null,
    ): UdbSessionCoordinator {
        if (null === $authority) {
            $defaultAuthority = $this->createStub(UdbAuthorityStateRepositoryInterface::class);
            $defaultAuthority->method('isApproved')->willReturn(false);
            $authority = $defaultAuthority;
        }

        return new UdbSessionCoordinator(
            '002',
            $this->blockStates,
            $this->snapshots,
            $logger ?? new NullLogger(),
            new UdbOclgView(),
            $takeover,
            $authority,
        );
    }

    private function createWireTakeover(LoggerInterface $logger = new NullLogger()): UdbWireTakeover
    {
        return new UdbWireTakeover(
            new FakeUdbRecords(),
            new FakeBlockStates(),
            $this->createStub(EntityManagerInterface::class),
            $this->createEmptyExporter(),
            $logger,
            $this->clock,
        );
    }

    /** Exporter over empty repositories: the SQL export yields no records. */
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

    /** @return list<array{0: UdbBlock, 1: string, 2: string}> */
    private function bootstrapRecords(): array
    {
        return [
            [UdbBlock::Nicks, 'alice::vhost', 'a.example'],
            [UdbBlock::Channels, '#chan::topic', 'welcome'],
            [UdbBlock::Ips, '1.2.3.4::clones', '*5'],
            [UdbBlock::Settings, 'propagator', 'hub1.example'],
            [UdbBlock::Links, 'hub1.example::options', '*1'],
            [UdbBlock::Lines, 'G::1.2.3.4', 'reason'],
        ];
    }

    private function captureConnection(UdbSessionCoordinator $coordinator): ConnectionInterface
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        return $connection;
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
        $this->startRound();
        $this->handle($this->peerHelAck());
    }

    /** Starts an authorized round but deliberately leaves its HEL barrier pending. */
    private function startRound(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());
    }

    private function activeRoundId(): int
    {
        $roundId = $this->coordinator->activeRoundId();

        self::assertIsInt($roundId);

        return $roundId;
    }

    private function expireOutstanding(): void
    {
        $this->clock->advance(300);
    }

    private function helRequest(): string
    {
        return ':002 DB 001 HEL 4 ' . self::OWN_NAME . ' ' . $this->epoch() . ' OCL OCLG';
    }

    private function helAck(): string
    {
        return ':002 DB 001 HEL 4 ACK ' . self::OWN_NAME . ' ' . $this->epoch() . ' OCL OCLG';
    }

    private function epoch(): string
    {
        $epoch = $this->coordinator->epoch();
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $epoch);

        return $epoch;
    }

    private function peerHel(
        string $propagator = self::OWN_NAME,
        string $sourceSid = '001',
        string $target = '002',
        string $epoch = self::PEER_EPOCH,
    ): UdbFrame {
        return new UdbFrame(
            UdbFrameKind::Hel,
            $sourceSid,
            $target,
            propagator: $propagator,
            epoch: $epoch,
            capabilities: ['OCL', 'OCLG'],
        );
    }

    private function peerHelAck(
        string $propagator = self::OWN_NAME,
        string $sourceSid = '001',
        string $target = '002',
        string $epoch = self::PEER_EPOCH,
    ): UdbFrame {
        return new UdbFrame(
            UdbFrameKind::HelAck,
            $sourceSid,
            $target,
            propagator: $propagator,
            epoch: $epoch,
            capabilities: ['OCL', 'OCLG'],
        );
    }

    private function firstWrittenLine(): string
    {
        foreach ($this->written as $line) {
            return $line;
        }

        self::fail('Expected at least one written line.');
    }

    private function extractTxid(string $line): string
    {
        $matches = array_fill(0, 2, '');
        if (1 !== preg_match('/:002 DB 001 BEGIN \d+ I (\S+) /', $line, $matches)) {
            self::fail('Expected a BEGIN frame with a transaction ID.');
        }

        return $matches[1];
    }

    #[Test]
    public function udbBarrierDeadlineExpiresAndReoffersWithoutIncomingTraffic(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        $this->clock->advance(60);
        $this->written = [];

        $this->coordinator->scheduleNextDeadlineTimer();
        self::assertNotNull($this->coordinator->getDeadlineWatcherId());

        $this->scheduler->runNext();

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->firstWrittenLine());
    }

    #[Test]
    public function udbStagedAckDeadlineExpiresWithoutIncomingTraffic(): void
    {
        $this->startRound();
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $this->expireOutstanding();
        $this->written = [];

        $this->coordinator->scheduleNextDeadlineTimer();
        self::assertNotNull($this->coordinator->getDeadlineWatcherId());

        $this->scheduler->runNext();

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->firstWrittenLine());
        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function enqueueMutationWithEventPumpFlushesImmediatelyWhenAuthorityReady(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->makeReady();

        $pump = new SessionEventPump();
        $this->coordinator->setEventPump($pump);

        $this->written = [];
        $this->coordinator->enqueueMutation(new UdbMutation('S', 'nickserv', 'NickServ!NickServ@services'));

        $pump->enqueue(static function () use ($pump): void {
            $pump->stop();
        });
        $pump->drain();

        self::assertContains(':002 DB * INS S::nickserv :NickServ!NickServ@services', $this->written);
    }

    #[Test]
    public function simultaneousDeadlineAndIncomingFrameEvaluatesDeadlineFirst(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        $this->clock->advance(60);
        $this->written = [];

        $this->coordinator->tick($this->connection);
        $this->handle($this->peerHelAck());

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->firstWrittenLine());
    }

    #[Test]
    public function udbSequenceAtomicityUnderBackpressure(): void
    {
        $this->startRound();
        $pump = new SessionEventPump();
        $this->coordinator->setEventPump($pump);

        $executionOrder = [];
        $suspendingConnection = $this->createStub(ConnectionInterface::class);
        $suspendingConnection->method('isConnected')->willReturn(true);
        $suspendingConnection->method('writeLine')->willReturnCallback(static function (string $line) use (&$executionOrder, $pump): void {
            $executionOrder[] = $line;
            if (str_contains($line, 'PUT')) {
                $pump->enqueue(static function () use (&$executionOrder, $pump): void {
                    $executionOrder[] = 'TIMER_OR_MUTATION_EXECUTED';
                    $pump->stop();
                });
            }
        });

        $this->connection = $suspendingConnection;

        $pump->enqueue(function (): void {
            $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));
        });

        $pump->drain();

        $putIndex = -1;
        $endIndex = -1;
        $timerIndex = -1;
        foreach ($executionOrder as $idx => $item) {
            if (str_contains($item, 'PUT')) {
                $putIndex = $idx;
            }
            if (str_contains($item, 'END')) {
                $endIndex = $idx;
            }
            if ('TIMER_OR_MUTATION_EXECUTED' === $item) {
                $timerIndex = $idx;
            }
        }

        self::assertNotSame(-1, $putIndex);
        self::assertNotSame(-1, $endIndex);
        self::assertNotSame(-1, $timerIndex);
        self::assertGreaterThan($endIndex, $timerIndex);
    }

    #[Test]
    public function tickAndScheduleNextDeadlineTimerNoOpWhenConnectionNull(): void
    {
        $this->coordinator->reset();
        $this->coordinator->tick();
        $this->coordinator->scheduleNextDeadlineTimer();
        self::assertNull($this->coordinator->getDeadlineWatcherId());
    }

    #[Test]
    public function retryWireBootstrapIsANoOpWhenTheStoreIsAlreadyApproved(): void
    {
        new ReflectionMethod(UdbSessionCoordinator::class, 'retryWireBootstrap')->invoke($this->coordinator);

        self::assertSame([], $this->written);
    }

    #[Test]
    public function deadlineWatcherWithEventPumpEnqueuesTickOnPump(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        $this->clock->advance(60);
        $this->written = [];

        $pump = new SessionEventPump();
        $this->coordinator->setEventPump($pump);

        $this->coordinator->scheduleNextDeadlineTimer();
        self::assertNotNull($this->coordinator->getDeadlineWatcherId());

        $this->scheduler->runNext();

        $pump->enqueue(static function () use ($pump): void {
            $pump->stop();
        });
        $pump->drain();

        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N /', $this->firstWrittenLine());
    }

    #[Test]
    public function queuedCallbackFromThePreviousLinkCannotTouchTheReplacementSession(): void
    {
        $pump = new SessionEventPump();
        $this->coordinator->setEventPump($pump);
        $this->prepareLink();

        // The scheduler callback has fired, but its serialized tick has not
        // run yet. This is the race where cancelling the watcher is too late.
        $this->scheduler->runNext();

        $this->coordinator->reset();
        $this->coordinator->setOwnName(self::OWN_NAME);
        $this->coordinator->onRemoteServer('003', 'replacement.example.net');
        $this->coordinator->onLinkReady($this->connection);
        $replacementWatcher = $this->coordinator->getDeadlineWatcherId();
        self::assertNotNull($replacementWatcher);

        $pump->enqueue(static function () use ($pump): void {
            $pump->stop();
        });
        $pump->drain();

        self::assertSame('replacement.example.net', $this->coordinator->getRemoteServerName());
        self::assertSame($replacementWatcher, $this->coordinator->getDeadlineWatcherId());
    }

    #[Test]
    public function cancelledSchedulerCallbackCannotTouchTheResetSession(): void
    {
        $this->prepareLink();
        $this->coordinator->reset();

        $this->scheduler->runCancelled();

        self::assertNull($this->coordinator->getDeadlineWatcherId());
    }
}
