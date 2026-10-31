<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAuthorityState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbAuthorityStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordMutationStoreInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Projection\Oclg\UdbOclgView;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbCapturedSnapshot;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbSnapshotProviderInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbHelloBarrier;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbPeerSession;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionCoordinator;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionLock;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbChannelSuspendReasonResolver;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutation;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutationQueue;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbWireTakeover;
use App\Irc\Adapter\Protocol\UnrealUdb\Transfer\UdbOutboundTransferTracker;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbProtocolHandler;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrameKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbOclgViewDigest;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbStructuralNodeCount;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbWireCodec;
use App\Irc\Adapter\Runtime\SessionEventPump;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_fill;
use function array_filter;
use function array_slice;
use function array_values;
use function chmod;
use function count;
use function file_put_contents;
use function hash;
use function implode;
use function pack;
use function preg_match;
use function preg_quote;
use function str_contains;
use function str_repeat;
use function strlen;
use function strtoupper;
use function substr_count;

#[CoversClass(UdbSessionCoordinator::class)]
#[CoversClass(UdbPeerSession::class)]
#[CoversClass(UdbStructuralNodeCount::class)]
final class UdbSessionCoordinatorTest extends DoctrineIntegrationTestCase
{
    private const string OWN_NAME = 'services.example.net';

    private const string PEER_EPOCH = '1111111111111111';

    private FakeBlockStates $blockStates;

    private FakeUdbRecords $records;

    private UdbSnapshotProviderInterface $snapshots;

    /** @var list<string> */
    private array $written = [];

    private ConnectionInterface $connection;

    private UdbSessionCoordinator $coordinator;

    private MutableUdbClock $clock;

    private DeterministicLoopScheduler $scheduler;

    // ---------- Link establishment ----------

    #[Test]
    public function linkReadyAnnouncesOurselvesAsPropagator(): void
    {
        $this->prepareLink();

        self::assertSame([$this->helRequest()], $this->written);
    }

    #[Test]
    public function outgoingFramesAreLoggedWithTheWireLine(): void
    {
        $logger = new RecordingLogger();
        $this->coordinator = $this->coordinatorWithLogger($logger);

        $this->prepareLink();

        self::assertSame(['> ' . $this->helRequest()], $logger->messages);
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
    public function reconciliationOfferDoesNothingBeforeThePeerIsAuthorized(): void
    {
        new ReflectionMethod(UdbSessionCoordinator::class, 'offerReconciliation')->invoke($this->coordinator);

        self::assertSame([], $this->written);
        self::assertNull($this->coordinator->activeRoundId());
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
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N [0-9a-f]{64} \d+ \d+ \d+ 002 0123456789abcdef$/', $this->written[1]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ C /', $this->written[2]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ I [0-9a-f]{64} 1 0 0 002 0123456789abcdef$/', $this->written[3]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ S /', $this->written[4]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ L /', $this->written[5]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ K /', $this->written[6]);
        self::assertSame($this->helRequest(), $this->written[7]);
    }

    #[Test]
    public function inventoryAndManifestAdvertiseStructuralNodesWithoutChangingDigests(): void
    {
        $recordsByBlock = [
            'N' => ['davidlig::vhost' => 'host.example', 'davidlig::oper' => 'netadmin', 'Ares::pass' => 'crypt:hash'],
            'C' => ['#ares::topic' => 'support', '#ares::modes' => '+nt'],
        ];
        $snapshots = new FakeUdbSnapshots($recordsByBlock);
        $this->coordinator = new UdbSessionCoordinator(
            '002',
            $this->blockStates,
            $snapshots,
            scheduler: $this->scheduler,
            clock: $this->clock,
        );

        $this->makeReady();
        $nDigest = UdbTestValues::digest(UdbBlock::Nicks, ['davidlig::vhost' => 'host.example', 'davidlig::oper' => 'netadmin', 'Ares::pass' => 'crypt:hash']);
        $cDigest = UdbTestValues::digest(UdbBlock::Channels, ['#ares::topic' => 'support', '#ares::modes' => '+nt']);
        $infLines = array_values(array_filter($this->written, static fn (string $line): bool => str_contains($line, ' INF ')));
        self::assertCount(6, $infLines);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N ' . $nDigest . ' 3 \d+ 0 002 0123456789abcdef$/', $infLines[0]);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ C ' . $cDigest . ' 2 \d+ 0 002 0123456789abcdef$/', $infLines[1]);

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::ManifestReq, '001', '002', roundId: 77));
        self::assertSame(':002 DB 001 MANIFEST ACK 77 N 3 ' . $nDigest . ' 0', $this->written[0]);
        self::assertSame(':002 DB 001 MANIFEST ACK 77 C 2 ' . $cDigest . ' 0', $this->written[1]);
        self::assertSame(':002 DB 001 MANIFEST ACK 77 I 0 ' . UdbChecksum::empty(UdbBlock::Ips) . ' 0', $this->written[2]);
    }

    #[Test]
    public function questionMarkPropagatorOffersSixBlocksOnlyAfterDirectHelloConfirmation(): void
    {
        $this->seedStore();
        $this->prepareLink();

        $this->written = [];
        $this->handle($this->peerHel('?', sourceSid: '999'));
        $this->handle($this->peerHel('?', target: '999'));
        self::assertSame([], $this->written);
        $this->handle($this->peerHel('?'));
        self::assertSame([$this->helAck()], $this->written);
        self::assertNull($this->coordinator->activeRoundId());
        $this->written = [];
        $this->handle($this->peerHelAck('?', sourceSid: '999'));
        $this->handle($this->peerHelAck('?', target: '999'));
        self::assertSame([], $this->written);
        $this->handle($this->peerHelAck('?'));
        self::assertCount(6, array_filter($this->written, static fn (string $line): bool => str_contains($line, ' INF ')));
        self::assertNotNull($this->coordinator->activeRoundId());
        self::assertFalse($this->coordinator->isAuthorityReady());
        $this->handle($this->peerHelAck('?'));
        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function invalidConstructedSelectorsPreserveConfirmedEpochAndAuthorization(): void
    {
        $peer = new UdbPeerSession();
        $barrier = new UdbHelloBarrier();
        $this->coordinator = new UdbSessionCoordinator('002', $this->blockStates, $this->snapshots, scheduler: $this->scheduler, clock: $this->clock, peer: $peer, helloBarrier: $barrier);
        $this->makeReady();
        self::assertTrue($barrier->isConfirmed());
        $this->written = [];
        foreach ([$this->peerHel('invalid', epoch: '2222222222222222'), $this->peerHelAck('bad/name', epoch: '2222222222222222')] as $invalid) {
            $this->handle($invalid);
            self::assertSame(self::PEER_EPOCH, $peer->remoteEpoch());
            self::assertTrue($peer->isAuthorized());
            self::assertTrue($barrier->isConfirmed());
            self::assertTrue($this->coordinator->isAuthorityReady());
            self::assertSame([], $this->written);
        }
    }

    #[Test]
    public function bootstrapRejectsSourcesDependingOnOurAuthorityBeforeAnyStaging(): void
    {
        foreach ([UdbFrameKind::Hel, UdbFrameKind::HelAck] as $kind) {
            $takeover = $this->createWireTakeover();
            $coordinator = $this->bootstrapCoordinator($takeover);
            $coordinator->setOwnName(self::OWN_NAME);
            $coordinator->onRemoteServer('001', 'ircd.example.net');
            $connection = $this->captureConnection($coordinator);
            $coordinator->onLinkReady($connection);
            $this->written = [];
            $coordinator->handleFrame(new UdbFrame($kind, '001', '002', propagator: strtoupper(self::OWN_NAME), epoch: self::PEER_EPOCH, capabilities: ['OCL', 'OCLG']), $connection);
            $coordinator->handleFrame($this->inventoryFrame(UdbBlock::Ips, hash('sha256', 'synthetic snapshot'), 1, 1), $connection);
            self::assertSame([], $this->written);
            self::assertNull($takeover->roundId());
            self::assertFalse($coordinator->isAuthorityReady());
        }
    }

    #[Test]
    public function bootstrapRejectsMalformedSelectorBeforeConfirmationOrInventoryAdmission(): void
    {
        $takeover = $this->createWireTakeover();
        $coordinator = $this->bootstrapCoordinator($takeover);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $this->written = [];
        $coordinator->handleFrame($this->peerHelAck('bad/name'), $connection);
        $coordinator->handleFrame($this->inventoryFrame(UdbBlock::Ips, hash('sha256', 'synthetic snapshot'), 1, 1), $connection);
        self::assertSame([], $this->written);
        self::assertNull($takeover->roundId());
        self::assertFalse($coordinator->isAuthorityReady());
    }

    #[Test]
    public function authorizedUpstreamBootstrapKeepsValidNonselectingAdvertisements(): void
    {
        $takeover = $this->createWireTakeover();
        $coordinator = $this->bootstrapCoordinator($takeover);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHelAck('-'), $connection);
        $this->written = [];
        $coordinator->handleFrame($this->inventoryFrame(UdbBlock::Ips, hash('sha256', 'synthetic snapshot'), 1, 1), $connection);
        self::assertSame([':002 DB 001 RES 1 I'], $this->written);
        self::assertNotNull($takeover->roundId());
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

        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        self::assertSame([
            ':002 DB 001 BEGIN 20 I 00000001 ' . $digest . ' 0',
            ':002 DB 001 PUT 20 I 00000001 I::1.2.3.4::clones :!u5',
            ':002 DB 001 END 20 I 00000001 ' . $digest . ' 0',
        ], $this->written);
        self::assertFalse($this->coordinator->isAuthorityReady());

        $this->handle($this->peerHelAck());
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest, watermark: 0));
        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function resIsRejectedWhileAuthorityIsNotEstablished(): void
    {
        $this->prepareLink();
        $this->handle($this->peerHelAck());

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
        $snapshots = new FakeUdbSnapshots();
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
        $snapshot = new ReflectionProperty(UdbSessionCoordinator::class, 'roundSnapshot')->getValue($coordinator);
        self::assertInstanceOf(UdbCapturedSnapshot::class, $snapshot);
        $files = new ReflectionProperty(UdbCapturedSnapshot::class, 'files')->getValue($snapshot);
        self::assertIsArray($files);
        self::assertIsString($files['I']);
        $path = str_repeat('x', 8193);
        chmod($files['I'], 0o600);
        file_put_contents($files['I'], pack('NCN', strlen($path), 1, 1) . $path . 'v');
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

        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: 'wrong', checksum: UdbChecksum::empty(UdbBlock::Ips)));

        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function ackWithWrongRoundDoesNotClearTheOutstandingTransfer(): void
    {
        $this->startRound();
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
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
    public function wrongWatermarkAndDuplicateAckCannotGrantOutboundCredit(): void
    {
        $this->startRound();
        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Settings));

        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest, watermark: 1));
        self::assertCount(1, array_filter($this->written, static fn (string $line): bool => str_contains($line, ' BEGIN ')));

        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest, watermark: 0));
        self::assertCount(2, array_filter($this->written, static fn (string $line): bool => str_contains($line, ' BEGIN ')));
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest, watermark: 0));

        self::assertCount(2, array_filter($this->written, static fn (string $line): bool => str_contains($line, ' BEGIN ')));
        self::assertStringContainsString(' BEGIN 20 S ', implode("\n", $this->written));
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
        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        self::assertSame(':002 DB 001 BEGIN 20 I 00000001 ' . $digest . ' 0', $this->written[0]);

        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));
        self::assertSame(':002 DB 001 ERR RES 4 20 I', $this->written[3]);
        self::assertCount(1, array_filter(
            $this->written,
            static fn (string $line): bool => str_contains($line, ' BEGIN '),
        ));

        $this->handle($this->peerHelAck());
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest, watermark: 0));
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
        $this->handle($this->peerHelAck());

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
        $this->handle($this->peerHelAck());

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
        $this->handle($this->peerHelAck());

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
        $this->handle($this->peerHelAck());
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
        $this->handle($this->peerHelAck());
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
        $this->handle($this->peerHelAck());

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
        $this->handle(new UdbFrame(UdbFrameKind::Ins, '001', '*', path: 'S::nickserv', value: 'mask'));
        $this->handle(new UdbFrame(UdbFrameKind::Del, '001', '*', path: 'K::G::x'));
        $this->handle(new UdbFrame(UdbFrameKind::Del, '001', '*', path: 'K::Z::5.6.7.8'));

        self::assertSame([
            ':002 DB 001 ERR INS 6 1 S',
            ':002 DB 001 ERR DEL 6 2 K',
            ':002 DB 001 ERR DEL 6 3 K',
        ], $this->written);
    }

    // ---------- HEL capability gate ----------

    #[Test]
    public function preHelloBroadcastMutationsAreIgnored(): void
    {
        $this->prepareLink();
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::Ins, '001', '*', path: 'S::nickserv', value: 'mask'));
        $this->handle(new UdbFrame(UdbFrameKind::Del, '001', '*', path: 'K::G::x'));
        $this->handle(new UdbFrame(UdbFrameKind::Drp, '001', '*', block: UdbBlock::Ips));

        self::assertSame([], $this->written);
        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function preHelloDirectFramesDoNotAdvanceState(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::Inf, '001', '002', roundId: 1, block: UdbBlock::Ips, checksum: UdbChecksum::empty(UdbBlock::Ips), timestamp: 1));
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 1, block: UdbBlock::Ips));
        $this->handle(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: UdbChecksum::empty(UdbBlock::Ips)));
        $this->handle(new UdbFrame(UdbFrameKind::Put, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', path: 'p', value: 'v'));
        $this->handle(new UdbFrame(UdbFrameKind::End, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: UdbChecksum::empty(UdbBlock::Ips)));
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: UdbChecksum::empty(UdbBlock::Ips)));
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: 1, block: UdbBlock::Ips, subcommand: 'PUT', errorCode: 3));
        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 1, epoch: self::PEER_EPOCH, status: 'READY', count: 1, checksum: str_repeat('a', 64)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 1, epoch: self::PEER_EPOCH, path: 'netadmin', checksum: str_repeat('a', 64)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 1, epoch: self::PEER_EPOCH));

        self::assertSame([], $this->written);
        self::assertNull($this->coordinator->activeRoundId());
        self::assertFalse($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function helAndHelAckAreProcessedBeforeConfirmation(): void
    {
        $this->prepareLink();
        $this->written = [];

        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        self::assertSame([$this->helAck()], $this->written);
    }

    #[Test]
    public function foreignSidMutationIsIgnoredBeforeAndAfterHello(): void
    {
        $this->prepareLink();
        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Ins, '999', '*', path: 'S::nickserv', value: 'mask'));
        self::assertSame([], $this->written);

        $this->makeReady();
        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Ins, '999', '*', path: 'S::nickserv', value: 'mask'));
        self::assertSame([], $this->written);
    }

    #[Test]
    public function epochChangeClosesTheGateUntilTheNewHelIsConfirmed(): void
    {
        $this->makeReady();
        $newEpoch = '2222222222222222';
        $this->handle($this->peerHel(epoch: $newEpoch));
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::Ins, '001', '*', path: 'S::nickserv', value: 'mask'));
        self::assertSame([], $this->written);

        $this->handle($this->peerHelAck(epoch: $newEpoch));
        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Ins, '001', '*', path: 'S::nickserv', value: 'mask'));

        self::assertSame([':002 DB 001 ERR INS 6 1 S'], $this->written);
    }

    #[Test]
    public function pendingReconciliationBarrierDoesNotBlockConfirmedTraffic(): void
    {
        $this->startRound();
        $roundId = $this->activeRoundId();
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: $roundId, block: UdbBlock::Ips));

        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        self::assertSame([
            ':002 DB 001 BEGIN ' . $roundId . ' I 00000001 ' . $digest . ' 0',
            ':002 DB 001 PUT ' . $roundId . ' I 00000001 I::1.2.3.4::clones :!u5',
            ':002 DB 001 END ' . $roundId . ' I 00000001 ' . $digest . ' 0',
        ], $this->written);
    }

    #[Test]
    public function nonDirectPeerResAndOclgFramesAreIgnoredAfterHello(): void
    {
        $this->makeReady();
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::Res, '999', '002', roundId: 20, block: UdbBlock::Ips));
        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '999', '002', roundId: 7, epoch: self::PEER_EPOCH, status: 'READY', count: 1, checksum: str_repeat('a', 64)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '999', '002', roundId: 7, epoch: self::PEER_EPOCH, path: 'netadmin', checksum: str_repeat('a', 64)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '999', '002', roundId: 7, epoch: self::PEER_EPOCH));

        self::assertSame([], $this->written);
        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));
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

        // Six consecutive ERR-triggered rounds are allowed; the seventh
        // would mean persistently rejected data and must stop the loop.
        for ($i = 0; 6 > $i; ++$i) {
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
        for ($i = 0; 6 > $i; ++$i) {
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
        for ($i = 0; 7 > $i; ++$i) {
            $this->handle($this->peerHelAck());
        }
        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: $roundId, block: UdbBlock::Ips, txid: $txid, checksum: $digest, watermark: 0));
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
    public function liveMutationsShareOneEpochScopedSequenceAcrossBlocks(): void
    {
        $this->makeReady();
        $this->written = [];

        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'N', 'alice::vhost', 'a.example'));
        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'S', 'nickserv', 'services.example'));
        $this->coordinator->tick($this->connection);

        self::assertSame([
            ':002 DB * INS ' . $this->coordinator->epoch() . ' 1 N::alice::vhost :!sYS5leGFtcGxl',
            ':002 DB * INS ' . $this->coordinator->epoch() . ' 2 S::nickserv :!sc2VydmljZXMuZXhhbXBsZQ==',
        ], $this->written);
    }

    #[Test]
    public function liveSecretMutationsAreLoggedRedacted(): void
    {
        $logger = new RecordingLogger();
        $this->coordinator = $this->coordinatorWithLogger($logger);

        $this->makeReady();
        $logger->messages = [];

        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'N', 'alice::pass', 'crypt:$2y$10$secret'));
        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'N', 'alice::vhost', 'a.example'));
        $this->coordinator->tick($this->connection);

        self::assertSame([
            '> :002 DB * INS ' . $this->coordinator->epoch() . ' 1 N::alice::pass :<redacted>',
            '> :002 DB * INS ' . $this->coordinator->epoch() . ' 2 N::alice::vhost :!sYS5leGFtcGxl',
        ], $logger->messages);
    }

    #[Test]
    public function exhaustedCommittedSequenceRejectsFurtherMutationAndRequestsRecovery(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Rejected duplicate or out-of-order committed UDB mutation sequence.');
        $coordinator = new UdbSessionCoordinator('002', $this->blockStates, $this->snapshots, $logger);
        new ReflectionClass(UdbSessionCoordinator::class)
            ->getProperty('mutationSequence')
            ->setValue($coordinator, UdbUnsignedDecimal::parse(UdbUnsignedDecimal::MAX));

        $coordinator->enqueueMutation(UdbTestValues::mutation('N', 'nick::vhost', 'host.example')->assigned(UdbUnsignedDecimal::parse(UdbUnsignedDecimal::MAX) ?? throw new LogicException('Invalid maximum fixture.'), new UdbStreamOrigin('002', $coordinator->epoch())));

        self::assertSame(0, $coordinator->queuedMutationCount());
        self::assertTrue($coordinator->isResyncPending());
    }

    #[Test]
    public function manifestRequestReturnsSixCountedDigestsAtOneWatermark(): void
    {
        $this->makeReady();
        $this->written = [];
        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'N', 'alice::vhost', 'a.example'));

        $this->handle(new UdbFrame(UdbFrameKind::ManifestReq, '001', '002', roundId: 77));

        $manifestLines = array_values(array_filter($this->written, static fn (string $line): bool => str_contains($line, ' MANIFEST ACK ')));
        self::assertCount(6, $manifestLines);
        foreach ($manifestLines as $line) {
            self::assertMatchesRegularExpression('/^:002 DB 001 MANIFEST ACK 77 [NCISLK] \d+ [0-9a-f]{64} 1$/', $line);
        }
    }

    #[Test]
    public function malformedOrNonDirectManifestRequestsAreIgnored(): void
    {
        $this->makeReady();
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::ManifestReq, '999', '002', roundId: 77));
        $this->handle(new UdbFrame(UdbFrameKind::ManifestReq, '001', '002'));

        self::assertSame([], $this->written);
    }

    #[Test]
    public function manifestRequestBeforeAuthoritySelectionIsRejected(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->handle($this->peerHel('-'));
        $this->handle($this->peerHelAck('-'));
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::ManifestReq, '001', '002', roundId: 77));

        self::assertSame([':002 DB 001 ERR MANIFEST 6 77 0'], $this->written);
    }

    #[Test]
    public function manifestResServesBlockAndAcksCleanly(): void
    {
        $this->makeReady();
        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'I', '1.2.3.4::clones', '*5'));
        $this->handle(new UdbFrame(UdbFrameKind::ManifestReq, '001', '002', roundId: 77));

        self::assertSame(77, $this->coordinator->activeRoundId());

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 77, block: UdbBlock::Ips));

        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        self::assertSame(':002 DB 001 BEGIN 77 I 00000001 ' . $digest . ' 1', $this->written[0]);
        self::assertSame(':002 DB 001 PUT 77 I 00000001 I::1.2.3.4::clones :!u5', $this->written[1]);
        self::assertSame(':002 DB 001 END 77 I 00000001 ' . $digest . ' 1', $this->written[2]);

        // Duplicate RES during active transfer returns ERR RES 4 (SYNC_ACTIVE)
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 77, block: UdbBlock::Ips));
        self::assertSame(':002 DB 001 ERR RES 4 77 I', $this->written[3]);

        // Unknown roundId returns ERR RES 5
        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 99, block: UdbBlock::Nicks));
        self::assertSame([':002 DB 001 ERR RES 5 99 N'], $this->written);

        // Acknowledging transfer settles the round
        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 77, block: UdbBlock::Ips, txid: '00000001', checksum: $digest, watermark: 1));

        self::assertTrue($this->coordinator->isAuthorityReady());
        self::assertNull($this->coordinator->activeRoundId());

        // RES after completion returns ERR RES 5
        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 77, block: UdbBlock::Ips));
        self::assertSame([':002 DB 001 ERR RES 5 77 I'], $this->written);
    }

    #[Test]
    public function manifestRequestWhileReconciliationIsActiveReturnsSyncActiveError(): void
    {
        $this->startRound();
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::ManifestReq, '001', '002', roundId: 77));

        self::assertSame([':002 DB 001 ERR MANIFEST 4 77 0'], $this->written);
    }

    #[Test]
    public function manifestRoundExpiresCleanlyWithoutReofferWhenZeroDivergence(): void
    {
        $this->makeReady();
        $this->handle(new UdbFrame(UdbFrameKind::ManifestReq, '001', '002', roundId: 77));
        self::assertSame(77, $this->coordinator->activeRoundId());

        $this->written = [];
        $this->clock->advance(61);
        $this->coordinator->tick($this->connection);

        // No reconciliation offer was triggered
        self::assertSame([], $this->written);
        self::assertNull($this->coordinator->activeRoundId());
        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function manifestResAfterDeadlineIsRejectedWithStaleRound(): void
    {
        $this->makeReady();
        $this->handle(new UdbFrame(UdbFrameKind::ManifestReq, '001', '002', roundId: 77));

        $this->clock->advance(61);
        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 77, block: UdbBlock::Ips));

        self::assertSame([':002 DB 001 ERR RES 5 77 I'], $this->written);
    }

    #[Test]
    public function peerErrorDuringManifestRoundTransferTriggersReoffer(): void
    {
        $this->makeReady();
        $this->handle(new UdbFrame(UdbFrameKind::ManifestReq, '001', '002', roundId: 77));
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 77, block: UdbBlock::Ips));

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Err, '001', '002', subcommand: 'PUT', errorCode: 3, roundId: 77, block: UdbBlock::Ips));

        $infLines = array_values(array_filter($this->written, static fn (string $line): bool => str_contains($line, ' INF ')));
        self::assertCount(6, $infLines);
    }

    #[Test]
    public function reconciliationInventoryUsesOneWatermarkForAllSixBlocks(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'N', 'alice::vhost', 'a.example'));
        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'C', '#chat::topic', 'welcome'));

        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        $inventory = array_values(array_filter($this->written, static fn (string $line): bool => str_contains($line, ' INF ')));
        self::assertCount(6, $inventory);
        foreach ($inventory as $line) {
            self::assertStringEndsWith(' 2 002 0123456789abcdef', $line);
        }
    }

    #[Test]
    public function expiryRequestDeletesOnlyTheMatchingExpiredLineProfile(): void
    {
        $this->makeReady();
        $this->records->blocks['K'] = [
            'G::*@bad.example::expires' => '*20',
            'G::*@bad.example::reason' => 'abuse',
        ];
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::Exp, '001', '002', path: 'K::G::*@bad.example', expectedExpires: 20));

        self::assertSame([], $this->records->blocks['K']);
        self::assertContains(':002 DB * DEL ' . $this->coordinator->epoch() . ' 1 K::G::*@bad.example', $this->written);
    }

    #[Test]
    public function staleExpiryRequestDoesNotDeleteTheProfile(): void
    {
        $this->makeReady();
        $this->records->blocks['K'] = [
            'G::*@bad.example::expires' => '*21',
            'G::*@bad.example::reason' => 'abuse',
        ];
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::Exp, '001', '002', path: 'K::G::*@bad.example', expectedExpires: 20));

        self::assertCount(2, $this->records->blocks['K']);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function malformedOrNonDirectExpiryRequestsAreIgnored(): void
    {
        $this->makeReady();
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::Exp, '999', '002', path: 'K::G::*@bad.example', expectedExpires: 20));
        $this->handle(new UdbFrame(UdbFrameKind::Exp, '001', '002', expectedExpires: 20));
        $this->handle(new UdbFrame(UdbFrameKind::Exp, '001', '002', path: 'K::G::*@bad.example'));

        self::assertSame([], $this->written);
    }

    #[Test]
    public function expiryRequestWithoutMutationStoreIsRejected(): void
    {
        $this->coordinator = new UdbSessionCoordinator(
            '002',
            $this->blockStates,
            $this->snapshots,
            scheduler: $this->scheduler,
            clock: $this->clock,
        );
        $this->makeReady();
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::Exp, '001', '002', path: 'K::G::*@bad.example', expectedExpires: 20));

        self::assertSame([':002 DB 001 ERR EXP 6 1 K'], $this->written);
    }

    #[Test]
    public function expiryPersistenceFailureReturnsFatalWithoutPublishingDelete(): void
    {
        $this->makeReady();
        $this->records->blocks['K'] = [
            'G::*@bad.example::expires' => '*20',
            'G::*@bad.example::reason' => 'abuse',
        ];
        $this->records->fail = true;
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::Exp, '001', '002', path: 'K::G::*@bad.example', expectedExpires: 20));

        self::assertSame([':002 DB 001 ERR EXP 3 1 K'], $this->written);
        self::assertCount(2, $this->records->blocks['K']);
    }

    #[Test]
    public function legacyUnsequencedQueueEntryIsNeverWritten(): void
    {
        $queue = new UdbMutationQueue();
        $queue->enqueue(UdbTestValues::mutation('N', 'legacy::vhost', 'host.example'));
        $this->coordinator = new UdbSessionCoordinator(
            '002',
            $this->blockStates,
            $this->snapshots,
            scheduler: $this->scheduler,
            clock: $this->clock,
            mutationQueue: $queue,
            mutations: $this->records,
        );

        $this->makeReady();

        self::assertSame(0, $this->coordinator->queuedMutationCount());
        self::assertStringNotContainsString('legacy::vhost', implode("\n", $this->written));
    }

    #[Test]
    public function queuedMutationsAreFlushedOnceAuthorityIsReady(): void
    {
        $this->seedStore();
        $this->prepareLink();

        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'S', 'nickserv', 'NickServ!NickServ@services'));
        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'S', 'nickserv', null));

        self::assertNotContains(':002 DB * INS S::nickserv :!sTmlja1NlcnYhTmlja1NlcnZAc2VydmljZXM=', $this->written);

        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());
        $this->handle($this->peerHelAck());

        self::assertSame(0, $this->coordinator->queuedMutationCount());
    }

    #[Test]
    public function mutationQueueOverflowDropsTheOldestEntry(): void
    {
        $this->prepareLink();

        for ($i = 0; 1025 > $i; ++$i) {
            $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'N', 'nick' . $i . '::vhost', 'v'));
        }

        self::assertSame(1024, $this->coordinator->queuedMutationCount());

        $this->seedStore();
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());
        $this->handle($this->peerHelAck());
        $lines = implode("\n", $this->written);
        self::assertStringNotContainsString('N::nick0::vhost ', $lines);
        self::assertStringNotContainsString('N::nick1::vhost ', $lines);
        self::assertStringNotContainsString('N::nick1024::vhost ', $lines);
        self::assertSame(0, $this->coordinator->queuedMutationCount());
    }

    #[Test]
    public function mutationQueueOverflowDuringActiveTransferDefersOneRecoveryRound(): void
    {
        $this->useSmallMutationQueue();
        $this->startRound();
        // Mid-transfer: outstanding blocks the flush path, so mutations queue.
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));
        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);

        $this->written = [];
        for ($i = 0; 3 > $i; ++$i) {
            $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'N', 'nick' . $i . '::vhost', 'v'));
        }

        self::assertSame([], $this->written);
        self::assertSame(20, $this->coordinator->activeRoundId());
        self::assertTrue($this->coordinator->isResyncPending());

        // The original transfer and barrier still correlate and settle first.
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest, watermark: 0));
        self::assertSame(20, $this->coordinator->activeRoundId());
        $this->handle($this->peerHelAck());

        self::assertSame(21, $this->coordinator->activeRoundId());
        self::assertSame(6, substr_count(implode("\n", $this->written), ' INF 21 '));
        self::assertTrue($this->coordinator->isResyncPending());
    }

    #[Test]
    public function mutationQueueOverflowInReadyStartsExactlyOneRecoveryRound(): void
    {
        $this->useSmallMutationQueue();
        $this->makeReady();
        $this->written = [];

        for ($i = 0; 3 > $i; ++$i) {
            $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'N', 'nick' . $i . '::vhost', 'v'));
        }

        self::assertSame(21, $this->coordinator->activeRoundId());
        self::assertSame(6, substr_count(implode("\n", $this->written), ' INF 21 '));
        self::assertSame(0, substr_count(implode("\n", $this->written), ' INF 22 '));

        $this->handle($this->peerHelAck());
        self::assertFalse($this->coordinator->isResyncPending());
        self::assertNull($this->coordinator->activeRoundId());
        self::assertSame(0, $this->coordinator->queuedMutationCount());
    }

    #[Test]
    public function overflowDebtSurvivesReconnectAndIsCoveredByTheFreshRound(): void
    {
        $this->useSmallMutationQueue();
        $this->startRound();
        for ($i = 0; 3 > $i; ++$i) {
            $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'N', 'nick' . $i . '::vhost', 'v'));
        }
        self::assertTrue($this->coordinator->isResyncPending());

        $this->coordinator->reset();
        self::assertTrue($this->coordinator->isResyncPending());
        $this->written = [];
        $this->prepareLink();
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        self::assertSame(21, $this->coordinator->activeRoundId());
        self::assertSame(6, substr_count(implode("\n", $this->written), ' INF 21 '));
    }

    #[Test]
    public function inFlightRecoveryDebtSurvivesReset(): void
    {
        $this->useSmallMutationQueue();
        $this->makeReady();

        for ($i = 0; 3 > $i; ++$i) {
            $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'N', 'nick' . $i . '::vhost', 'v'));
        }

        self::assertSame(21, $this->coordinator->activeRoundId());
        self::assertTrue($this->coordinator->isResyncPending());

        $this->coordinator->reset();

        self::assertTrue($this->coordinator->isResyncPending());
        self::assertNull($this->coordinator->activeRoundId());
    }

    // ---------- Lifecycle ----------

    #[Test]
    public function resetClearsVolatileStateAndKeepsTheQueue(): void
    {
        $this->makeReady();
        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'S', 'nickserv', 'mask'));

        $this->coordinator->reset();

        self::assertFalse($this->coordinator->isAuthorityReady());

        // After reset the link starts over: HEL is announced again.
        $this->written = [];
        $this->coordinator->onStoreInitialized();
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

        self::assertSame([$this->helRequest(), $this->helAck()], $this->written);
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
        $transfers->track(UdbBlock::Ips, 20, 'occupied', UdbChecksum::empty(UdbBlock::Ips), UdbUnsignedDecimal::fromInt(0), 20);
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
        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $this->coordinator->reset();
        $this->prepareLink();
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());
        self::assertSame(21, $this->coordinator->activeRoundId());

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 21, block: UdbBlock::Ips));
        self::assertSame(':002 DB 001 BEGIN 21 I 00000002 ' . $digest . ' 0', $this->written[0]);
        $this->handle($this->peerHelAck());
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest, watermark: 0));
        self::assertFalse($this->coordinator->isAuthorityReady());

        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 21, block: UdbBlock::Ips, txid: '00000002', checksum: $digest, watermark: 0));
        self::assertTrue($this->coordinator->isAuthorityReady());
    }

    #[Test]
    public function ackErrAndBarrierFromANonDirectPeerCannotAdvanceOrAbortTheRound(): void
    {
        $this->startRound();
        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));
        $this->written = [];

        $this->handle($this->peerHelAck(sourceSid: '999'));
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '999', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest, watermark: 0));
        $this->handle(new UdbFrame(UdbFrameKind::Err, '999', '002', roundId: 20, block: UdbBlock::Ips, subcommand: 'PUT', errorCode: 3));

        self::assertSame([], $this->written);
        self::assertSame(20, $this->coordinator->activeRoundId());
        self::assertFalse($this->coordinator->isAuthorityReady());

        $this->handle($this->peerHelAck());
        $this->handle(new UdbFrame(UdbFrameKind::Ack, '001', '002', roundId: 20, block: UdbBlock::Ips, txid: '00000001', checksum: $digest, watermark: 0));
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
        $this->coordinator->onStoreInitialized();
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
        $this->coordinator->onStoreInitialized();
        $this->coordinator->setOwnName(self::OWN_NAME);
        $this->coordinator->onRemoteServer('001', 'ircd.example.net');

        $this->handle($this->peerHel());

        // Our HEL request goes out before the ACK, so the peer emits its ACK
        // to it (which opens our gate) before replaying the OCLG snapshot.
        self::assertSame([
            $this->helRequest(),
            $this->helAck(),
        ], $this->written);

        $this->written = [];
        $this->handle($this->peerHelAck());

        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 7, epoch: self::PEER_EPOCH, status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 7, epoch: self::PEER_EPOCH, path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 7, epoch: self::PEER_EPOCH));

        self::assertTrue($this->coordinator->isOperclassGloballyAvailable('netadmin'));
        self::assertSame(['netadmin'], $this->coordinator->getAvailableOperclasses());
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
        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'S', 'nickserv', 'mask'));
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
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
        $this->written = [];

        // The peer first advertises the inventory. Services request the
        // divergent block with RES before accepting BEGIN/PUT/END.
        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
        $coordinator->handleFrame($this->inventoryFrame(UdbBlock::Ips, $digest, 1, 1), $connection);
        self::assertSame(':002 DB 001 RES 1 I', $this->firstWrittenLine());
        $this->written = [];
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: $digest, watermark: 0), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Put, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', path: '1.2.3.4::clones', value: '*5', typedValue: UdbTestValues::fromLogical(UdbBlock::Ips, '1.2.3.4::clones', '*5')), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::End, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: $digest, watermark: 0), $connection);

        self::assertMatchesRegularExpression('/^:002 DB 001 ACK 1 I tx1 ' . $digest . ' 0$/', $this->firstWrittenLine());
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

        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Inf, '001', '002', roundId: 1, block: UdbBlock::Ips, checksum: UdbChecksum::empty(UdbBlock::Ips), timestamp: 1), $connection);

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
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
        $this->written = [];

        $coordinator->handleFrame(new UdbFrame(
            UdbFrameKind::Begin,
            '001',
            '002',
            roundId: 7,
            block: UdbBlock::Ips,
            txid: 'tx1',
            checksum: UdbChecksum::empty(UdbBlock::Ips),
        ), $connection);

        self::assertSame([':002 DB 001 ERR BEGIN 5 7 I', ':002 DB 001 HEL 4 ? ' . $coordinator->epoch() . ' OCL OCLG'], $this->written);
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
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
        $this->written = [];

        $coordinator->handleFrame($this->inventoryFrame(UdbBlock::Ips, UdbChecksum::empty(UdbBlock::Ips), 7, 0), $connection);
        $coordinator->handleFrame($this->inventoryFrame(UdbBlock::Settings, UdbChecksum::empty(UdbBlock::Settings), 8, 0), $connection);

        self::assertSame([
            ':002 DB 001 ERR INF 5 8 S',
            ':002 DB 001 HEL 4 ? ' . $coordinator->epoch() . ' OCL OCLG',
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
        $coordinator->handleFrame($this->peerHel(propagator: 'hub1.example'), $connection);
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);

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
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
        $this->written = [];

        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
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
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
        $coordinator->handleFrame($this->inventoryFrame(UdbBlock::Ips, UdbChecksum::empty(UdbBlock::Ips), 1, 0), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: UdbChecksum::empty(UdbBlock::Ips), watermark: 0), $connection);

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
        $coordinator->handleFrame($this->peerHel(), $connection);
        $coordinator->handleFrame($this->peerHelAck(), $connection);
        $this->written = [];

        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: str_repeat('0', 8)), $connection);

        self::assertSame([':002 DB 001 ERR BEGIN 6 1 I'], $this->written);
    }

    #[Test]
    public function wireBootstrapCompletesApprovesAndRenegotiatesAsFqdnAuthority(): void
    {
        $records = new FakeUdbRecords();
        $states = new FakeBlockStates();
        $takeover = new UdbWireTakeover($records, $states, $this->entityManager, $this->createEmptyExporter());

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
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
        // The peer announces itself (its HEL authorizes the bootstrap exchange).
        $coordinator->handleFrame($this->peerHel(propagator: 'hub1.example'), $connection);
        $this->written = [];

        // Stage every block over the wire (SQL-owned blocks are discarded).
        foreach ($this->bootstrapRecords() as [$block, $path, $value]) {
            $this->stageBootstrapRecord($coordinator, $connection, $block, $path, $value);
        }

        // Transport ordering alone is insufficient; only the exact native 402 gate
        // promotes the durably validated imported generation.
        $lines = implode("\n", $this->written);
        self::assertMatchesRegularExpression('/^PING 001 :UDB4-1-[0-9a-f]{32}$/m', $lines);
        self::assertStringNotContainsString('HEL 4 services.example.net', $lines);
        self::assertStringNotContainsString(':002 DB 001 INF', $lines);

        preg_match('/^PING 001 :(UDB4-1-[0-9a-f]{32})$/m', $lines, $ping);
        self::assertArrayHasKey(1, $ping);
        $coordinator->handlePong('001', $ping[1], $connection);
        $coordinator->handleTransportBarrier('001', 'wrong-local.test', $ping[1], $connection);
        $coordinator->handleTransportBarrier('001', self::OWN_NAME, $ping[1], $this->createStub(ConnectionInterface::class));
        $coordinator->handleTransportBarrier('999', self::OWN_NAME, $ping[1], $connection);
        $coordinator->handleTransportBarrier(null, self::OWN_NAME, $ping[1], $connection);
        $coordinator->handleTransportBarrier('001', self::OWN_NAME, 'UDB4-1-stale', $connection);
        self::assertStringNotContainsString('HEL 4 services.example.net', implode("\n", $this->written));
        // Route through the real handler: its pre-dispatch tick replaces the
        // coordinator's mutable current connection, which must not rebind
        // the outstanding barrier's original transport identity.
        $handler = new UnrealUdbProtocolHandler('002', $coordinator, new UdbSessionLock(sys_get_temp_dir()));
        $foreign = $this->createStub(ConnectionInterface::class);
        $response = IRCMessage::fromRawLine(':ircd.example.net 402 ' . self::OWN_NAME . ' ' . $ping[1] . ' :No such server');
        $handler->handleIncoming($response, $foreign);
        self::assertFalse($takeover->canPromote());
        self::assertStringNotContainsString('HEL 4 services.example.net', implode("\n", $this->written));
        $handler->handleIncoming(IRCMessage::fromRawLine(':ircd.example.net 402 ' . self::OWN_NAME . ' UDB4-1-stale :No such server'), $foreign);
        self::assertFalse($takeover->canPromote());
        $handler->handleIncoming($response, $connection);
        $writtenAfterPromotion = $this->written;
        $handler->handleIncoming($response, $foreign);
        self::assertSame($writtenAfterPromotion, $this->written);
        $coordinator->handleTransportBarrier('001', self::OWN_NAME, $ping[1], $connection);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 services\.example\.net [0-9a-f]{16} OCL OCLG$/m', implode("\n", $this->written));

        $coordinator->handleFrame($this->peerHelAck(), $connection);
        $lines = implode("\n", $this->written);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N [0-9a-f]{64} \d+ \d+ \d+ 002 0123456789abcdef$/m', $lines);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ K /m', $lines);
    }

    #[Test]
    public function realHandlerPreInventoryTailBindsPrunesAndPromotesOnlyAtExactTransportBarrier(): void
    {
        $authority = $this->createMock(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(false);
        $authority->expects(self::once())->method('approve');
        $takeover = new UdbWireTakeover($this->records, $this->blockStates, $this->entityManager, $this->createEmptyExporter(), clock: $this->clock);
        $coordinator = new UdbSessionCoordinator('002', $this->blockStates, $this->snapshots, wireTakeover: $takeover, authority: $authority, scheduler: $this->scheduler, clock: $this->clock);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $handler = new UnrealUdbProtocolHandler('002', $coordinator, new UdbSessionLock(sys_get_temp_dir()));
        $handler->handleIncoming(IRCMessage::fromRawLine(':001 DB 002 HEL 4 ACK hub1.example ' . self::PEER_EPOCH . ' OCL OCLG'), $connection);
        self::assertNull($coordinator->getDeadlineWatcherId());
        $this->written = [];
        $rootEpoch = '3333333333333333';
        foreach ([
            ':003 DB * INS ' . $rootEpoch . ' 40 I::192.0.2.1::clones :!u8',
            ':003 DB * DEL ' . $rootEpoch . ' 41 I::192.0.2.2',
            ':003 DB * INS ' . $rootEpoch . ' 42 I::192.0.2.1::clones :!u1',
        ] as $line) {
            $handler->handleIncoming(IRCMessage::fromRawLine($line), $connection);
        }
        self::assertNotNull($coordinator->getDeadlineWatcherId());
        self::assertNull($takeover->roundId());
        self::assertSame([], $this->capturedWrites());
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM udb_staged_mutations'));
        $blocks = UdbBlock::all();
        foreach (array_slice($blocks, 0, 5) as $block) {
            $handler->handleIncoming(IRCMessage::fromRawLine(UdbWireCodec::inf('001', '002', 1, $block, UdbChecksum::empty($block), 0, 41, '003', $rootEpoch)), $connection);
        }
        self::assertFalse($takeover->canPromote());
        self::assertSame([], new UdbRecordDoctrineRepository($this->entityManager)->recordsByBlock('I'));
        self::assertSame(['42'], $this->entityManager->getConnection()->fetchFirstColumn('SELECT sequence FROM udb_staged_mutations'));
        $handler->handleIncoming(IRCMessage::fromRawLine(':003 DB * INS ' . $rootEpoch . ' 41 I::192.0.2.1::clones :!u9'), $connection);
        $last = $blocks[5];
        $handler->handleIncoming(IRCMessage::fromRawLine(UdbWireCodec::inf('001', '002', 1, $last, UdbChecksum::empty($last), 0, 41, '003', $rootEpoch)), $connection);
        preg_match('/^PING 001 :(UDB4-1-[0-9a-f]{32})$/m', implode("\n", $this->written), $ping);
        self::assertArrayHasKey(1, $ping);
        $handler->handleIncoming(IRCMessage::fromRawLine(':001 PONG 002 :' . $ping[1]), $connection);
        $handler->handleIncoming(IRCMessage::fromRawLine(':ircd.example.net 402 ' . self::OWN_NAME . ' UDB4-1-stale :No such server'), $connection);
        $response = IRCMessage::fromRawLine(':ircd.example.net 402 ' . self::OWN_NAME . ' ' . $ping[1] . ' :No such server');
        $foreign = $this->createStub(ConnectionInterface::class);
        $handler->handleIncoming($response, $foreign);
        self::assertFalse($takeover->canPromote());
        self::assertSame([], new UdbRecordDoctrineRepository($this->entityManager)->recordsByBlock('I'));
        $handler->handleIncoming($response, $connection);
        self::assertSame(['192.0.2.1::clones' => '*1'], UdbTestValues::logicalMap(new UdbRecordDoctrineRepository($this->entityManager)->recordsByBlock('I')));
        $checkpoint = $this->entityManager->find(UdbAuthorityState::class, 1)?->getImportedCheckpoint();
        self::assertNotNull($checkpoint);
        self::assertSame('003', $checkpoint->origin->sid);
        self::assertSame($rootEpoch, $checkpoint->origin->epoch);
        self::assertSame('42', (string) $checkpoint->watermark);
        self::assertDoesNotMatchRegularExpression('/ DB .* ERR |HEL 4 \?/', implode("\n", $this->written));
    }

    #[Test]
    public function provisionalExpiryDisconnectAndEpochChangeClearBufferedOrigins(): void
    {
        $takeover = $this->createWireTakeover();
        $authority = $this->createStub(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(false);
        $coordinator = new UdbSessionCoordinator('002', $this->blockStates, $this->snapshots, wireTakeover: $takeover, authority: $authority, scheduler: $this->scheduler, clock: $this->clock);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
        $mutation = new UdbFrame(UdbFrameKind::Del, '003', '*', epoch: self::PEER_EPOCH, sequence: 40, path: 'N::nick');
        $coordinator->handleFrame($mutation, $connection);
        self::assertSame(80, $takeover->nextDeadline());
        $coordinator->handleFrame($this->peerHel(propagator: 'hub1.example', epoch: '2222222222222222'), $connection);
        self::assertNull($takeover->nextDeadline());
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example', epoch: '2222222222222222'), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Del, '004', '*', epoch: '2222222222222222', sequence: 10, path: 'N::nick'), $connection);
        self::assertSame(80, $takeover->nextDeadline());
        $coordinator->reset();
        self::assertNull($takeover->nextDeadline());
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
        $coordinator->handleFrame($mutation, $connection);
        $this->clock->advance(60);
        $this->written = [];
        $this->scheduler->runNext();
        self::assertNull($takeover->nextDeadline());
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 \? /', $this->firstWrittenLine());
    }

    #[Test]
    public function provisionalBindingAndPostInventorySqlFailuresRecoverWithoutPartialCredit(): void
    {
        $takeover = $this->createWireTakeover();
        $coordinator = $this->bootstrapCoordinator($takeover);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
        $mutation = new UdbFrame(UdbFrameKind::Ins, '001', '*', epoch: self::PEER_EPOCH, sequence: 1, path: 'I::192.0.2.1::clones', typedValue: UdbValue::number(UdbUnsignedDecimal::fromInt(1)));
        $coordinator->handleFrame($mutation, $connection);
        $sql = $this->entityManager->getConnection();
        $sql->executeStatement("CREATE TRIGGER reject_tail BEFORE INSERT ON udb_staged_mutations BEGIN SELECT RAISE(ABORT, 'controlled tail failure'); END");
        $this->written = [];
        $coordinator->handleFrame($this->inventoryFrame(UdbBlock::Ips, UdbChecksum::empty(UdbBlock::Ips), 1, 0), $connection);
        self::assertNull($takeover->roundId());
        self::assertSame(0, $sql->fetchOne('SELECT COUNT(*) FROM udb_staged_generations'));
        self::assertSame(0, $sql->fetchOne('SELECT COUNT(*) FROM udb_staged_mutations'));
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 \? /', $this->firstWrittenLine());
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
        $coordinator->handleFrame($this->inventoryFrame(UdbBlock::Ips, UdbChecksum::empty(UdbBlock::Ips), 2, 0), $connection);
        $this->written = [];
        $coordinator->handleFrame($mutation, $connection);
        self::assertNull($takeover->roundId());
        self::assertFalse($takeover->canPromote());
        self::assertSame(0, $sql->fetchOne('SELECT COUNT(*) FROM udb_staged_mutations'));
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 \? /', $this->firstWrittenLine());
    }

    #[Test]
    public function wireBootstrapReplaysAContiguousRelayedRootTailBeforePromotion(): void
    {
        $authority = $this->createMock(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(false);
        $authority->expects(self::once())->method('approve');
        $takeover = new UdbWireTakeover($this->records, $this->blockStates, $this->entityManager, $this->createEmptyExporter());
        $coordinator = $this->bootstrapCoordinator($takeover, authority: $authority, mutations: $this->records);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
        $coordinator->handleFrame($this->peerHel(propagator: 'hub1.example'), $connection);
        $this->written = [];

        foreach ($this->bootstrapRecords() as [$block, $path, $value]) {
            $this->stageBootstrapRecord($coordinator, $connection, $block, $path, $value, rootSid: '003', watermark: 41);
        }
        $coordinator->handleFrame(new UdbFrame(
            UdbFrameKind::Ins,
            '003', // Imported stream root; authorized direct transport peer is still 001.
            '*',
            epoch: self::PEER_EPOCH,
            sequence: 42,
            path: 'I::1.2.3.5::clones',
            value: '!u5',
            typedValue: UdbTestValues::fromLogical(UdbBlock::Ips, '1.2.3.5::clones', '*5'),
        ), $connection);
        $coordinator->handleFrame(new UdbFrame(
            UdbFrameKind::Ins,
            '003',
            '*',
            epoch: self::PEER_EPOCH,
            sequence: 43,
            path: 'I::1.2.3.6::clones',
            value: '!u4',
            typedValue: UdbTestValues::fromLogical(UdbBlock::Ips, '1.2.3.6::clones', '*4'),
        ), $connection);
        $coordinator->handleFrame(new UdbFrame(
            UdbFrameKind::Del,
            '003',
            '*',
            epoch: self::PEER_EPOCH,
            sequence: 44,
            path: 'I::1.2.3.5',
        ), $connection);
        $coordinator->handleFrame(new UdbFrame(
            UdbFrameKind::Drp,
            '003',
            '*',
            block: UdbBlock::Settings,
            epoch: self::PEER_EPOCH,
            sequence: 45,
        ), $connection);
        self::assertDoesNotMatchRegularExpression('/ DB 001 ERR INS /', implode("\n", $this->written));
        self::assertDoesNotMatchRegularExpression('/ DB 001 ERR DEL /', implode("\n", $this->written));
        self::assertDoesNotMatchRegularExpression('/ DB 001 ERR DRP /', implode("\n", $this->written));
        self::assertSame([], $this->records->recordsByBlock('I')); // Tail remains staged until the exact PONG.

        preg_match('/^PING 001 :(UDB4-1-[0-9a-f]{32})$/m', implode("\n", $this->written), $ping);
        self::assertArrayHasKey(1, $ping);
        $coordinator->handleTransportBarrier('001', self::OWN_NAME, $ping[1], $connection);

        self::assertTrue(
            [
                '1.2.3.4::clones' => UdbTestValues::fromLogical(UdbBlock::Ips, '1.2.3.4::clones', '*5'),
                '1.2.3.6::clones' => UdbTestValues::fromLogical(UdbBlock::Ips, '1.2.3.6::clones', '*4'),
            ] == new UdbRecordDoctrineRepository($this->entityManager)->recordsByBlock('I'),
        );
        self::assertSame([], $this->records->recordsByBlock('S'));
        $imported = $this->entityManager->find(UdbAuthorityState::class, 1)?->getImportedCheckpoint();
        self::assertNotNull($imported);
        self::assertSame('003', $imported->origin->sid);
        self::assertSame(self::PEER_EPOCH, $imported->origin->epoch);
        self::assertSame('45', (string) $imported->watermark);
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
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
        $this->written = [];

        // Complete a full six-block exchange with a broken authority store.
        foreach ($this->bootstrapRecords() as [$block, $path, $value]) {
            $this->stageBootstrapRecord($coordinator, $connection, $block, $path, $value);
        }

        preg_match('/^PING 001 :(UDB4-1-[0-9a-f]{32})$/m', implode("\n", $this->written), $ping);
        self::assertArrayHasKey(1, $ping);
        $coordinator->handleTransportBarrier('001', self::OWN_NAME, $ping[1], $connection);

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
        $coordinator->handleFrame($this->peerHel(), $connection);
        $coordinator->handleFrame($this->peerHelAck(), $connection);
        $this->written = [];

        $digest = UdbTestValues::digest(UdbBlock::Ips, ['1.2.3.4::clones' => '*5']);
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
            $this->blockStates->upsert($block->letter(), UdbChecksum::empty($block), 0);
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
        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'S', 'nickserv', 'NickServ!NickServ@services'));

        $pump->enqueue(static function () use ($pump): void {
            $pump->stop();
        });
        $pump->drain();

        self::assertContains(':002 DB * INS ' . $this->coordinator->epoch() . ' 1 S::nickserv :!sTmlja1NlcnYhTmlja1NlcnZAc2VydmljZXM=', $this->written);
    }

    #[Test]
    public function queuedMutationFlushesAreCoalescedWithoutDroppingMutations(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->makeReady();

        $pump = new SessionEventPump();
        $this->coordinator->setEventPump($pump);
        $this->written = [];
        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'S', 'first', 'one'));
        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'S', 'second', 'two'));

        self::assertSame(1, $pump->getQueueSize());
        $pump->enqueue(static function () use ($pump): void {
            $pump->stop();
        });
        $pump->drain();

        self::assertContains(':002 DB * INS ' . $this->coordinator->epoch() . ' 1 S::first :!sb25l', $this->written);
        self::assertContains(':002 DB * INS ' . $this->coordinator->epoch() . ' 2 S::second :!sdHdv', $this->written);
    }

    #[Test]
    public function mutationFlushEnqueueIsSkippedWhenTheEventPumpIsAtCapacity(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->makeReady();

        $pump = new SessionEventPump();
        $this->coordinator->setEventPump($pump);
        for ($number = 0; $number < SessionEventPump::MAX_QUEUE_SIZE; ++$number) {
            $pump->enqueue(static function (): void {});
        }

        $this->coordinator->enqueueMutation($this->committedMutation($this->coordinator, 'S', 'nickserv', 'NickServ!NickServ@services'));

        self::assertSame(SessionEventPump::MAX_QUEUE_SIZE, $pump->getQueueSize());

        $pump->stop();
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
    public function deadlineWatcherAtHighWaterRearmsInsteadOfGrowingTheQueue(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->handle($this->peerHel());
        $this->handle($this->peerHelAck());

        $this->clock->advance(60);
        $this->written = [];

        $pump = new SessionEventPump();
        $this->coordinator->setEventPump($pump);
        for ($number = 0; $number < SessionEventPump::READER_HIGH_WATER; ++$number) {
            $pump->enqueue(static function (): void {});
        }

        $this->coordinator->scheduleNextDeadlineTimer();
        $this->scheduler->runNext();

        self::assertSame(SessionEventPump::READER_HIGH_WATER, $pump->getQueueSize());
        self::assertNotNull($this->coordinator->getDeadlineWatcherId());

        $pump->stop();
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
        $this->coordinator->onStoreInitialized();
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
    public function newerDeadlineGenerationKeepsItsFifoPositionBehindQueuedFrames(): void
    {
        $events = [];
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });
        $this->connection->method('disconnect')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'deadline';
        });

        $pump = new SessionEventPump();
        $this->coordinator->setEventPump($pump);
        $this->prepareLink();
        $this->scheduler->runNext();

        $this->coordinator->reset();
        $this->coordinator->onStoreInitialized();
        $this->coordinator->setOwnName(self::OWN_NAME);
        $this->coordinator->onRemoteServer('003', 'replacement.example.net');
        $this->coordinator->onLinkReady($this->connection);
        $this->clock->advance(60);
        $pump->enqueue(function () use (&$events): void {
            $events[] = 'frame';
            $this->handle($this->peerHelAck(sourceSid: '003'));
        });
        $this->scheduler->runNext();

        $pump->enqueue(static function () use ($pump): void {
            $pump->stop();
        });
        $pump->drain();

        self::assertSame(['frame'], $events);
        self::assertSame('replacement.example.net', $this->coordinator->getRemoteServerName());
    }

    #[Test]
    public function cancelledSchedulerCallbackCannotTouchTheResetSession(): void
    {
        $this->prepareLink();
        $this->coordinator->reset();

        $this->scheduler->runCancelled();

        self::assertNull($this->coordinator->getDeadlineWatcherId());
    }

    #[Test]
    public function queuedAdmissionIsUniqueBoundedAndResetAcrossRounds(): void
    {
        $enqueue = new ReflectionMethod(UdbSessionCoordinator::class, 'enqueueRes');
        $round = UdbUnsignedDecimal::fromInt(1);
        foreach (UdbBlock::all() as $block) {
            self::assertTrue($enqueue->invoke($this->coordinator, $block, $round));
        }
        self::assertFalse($enqueue->invoke($this->coordinator, UdbBlock::Nicks, $round));
        $this->coordinator->reset();
        self::assertTrue($enqueue->invoke($this->coordinator, UdbBlock::Nicks, UdbUnsignedDecimal::fromInt(2)));
        self::assertFalse($enqueue->invoke($this->coordinator, UdbBlock::Nicks, UdbUnsignedDecimal::fromInt(2)));
    }

    #[Test]
    public function foreignOrUncommittedMutationsForceResyncWithoutWirePublication(): void
    {
        $this->coordinator->enqueueMutation(new UdbMutation('N', 'nick::pass', UdbValue::string('fixture')));
        self::assertTrue(new ReflectionProperty(UdbSessionCoordinator::class, 'resyncPending')->getValue($this->coordinator));
        self::assertSame([], $this->written);
    }

    #[Test]
    public function corruptedImportedTailFailsWithoutAuthorityApproval(): void
    {
        $takeover = $this->createWireTakeover();
        $coordinator = $this->bootstrapCoordinator($takeover);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $connection = $this->captureConnection($coordinator);
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
        $coordinator->handleFrame($this->peerHel(propagator: 'hub1.example'), $connection);
        foreach ($this->bootstrapRecords() as [$block, $path, $value]) {
            $this->stageBootstrapRecord($coordinator, $connection, $block, $path, $value);
        }
        $token = new ReflectionProperty(UdbSessionCoordinator::class, 'pendingTailBarrierToken')->getValue($coordinator);
        self::assertIsString($token);
        $this->entityManager->getConnection()->executeStatement('UPDATE udb_staged_transfers SET digest = ?', [str_repeat('a', 64)]);
        $this->written = [];
        $coordinator->handleTransportBarrier('001', self::OWN_NAME, $token, $connection);
        self::assertFalse($coordinator->isAuthorityReady());
        self::assertFalse($takeover->canPromote());
        self::assertStringNotContainsString('HEL 4 services.example.net', implode("\n", $this->written));
    }

    #[Test]
    public function bootstrapErrorAndRejectedGapCancelTheCurrentRound(): void
    {
        foreach ([false, true] as $gap) {
            $takeover = $this->createWireTakeover();
            $coordinator = $this->bootstrapCoordinator($takeover);
            $coordinator->setOwnName(self::OWN_NAME);
            $coordinator->onRemoteServer('001', 'ircd.example.net');
            $connection = $this->captureConnection($coordinator);
            $coordinator->onLinkReady($connection);
            $coordinator->handleFrame($this->peerHelAck(propagator: 'hub1.example'), $connection);
            $coordinator->handleFrame($this->peerHel(propagator: 'hub1.example'), $connection);
            $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Inf, '001', '002', roundId: 1, block: UdbBlock::Ips, checksum: str_repeat('a', 64), count: 1, watermark: 0, rootSid: '001', rootEpoch: self::PEER_EPOCH), $connection);
            self::assertNotNull($takeover->roundId());
            $frame = $gap
                ? new UdbFrame(UdbFrameKind::Del, '001', '*', epoch: self::PEER_EPOCH, sequence: 2, path: 'I::192.0.2.1')
                : new UdbFrame(UdbFrameKind::Err, '001', '002', roundId: 1, block: UdbBlock::Ips, subcommand: 'BEGIN', errorCode: 5);
            $coordinator->handleFrame($frame, $connection);
            self::assertNull($takeover->roundId());
            self::assertFalse($coordinator->isAuthorityReady());
        }
    }

    #[Test]
    public function committedBlockDropFlushesButAnUnassignedQueueEntryNeverDoes(): void
    {
        $this->makeReady();
        self::assertTrue($this->coordinator->isAuthorityReady());
        $queue = new ReflectionProperty(UdbSessionCoordinator::class, 'mutationQueue')->getValue($this->coordinator);
        self::assertInstanceOf(UdbMutationQueue::class, $queue);
        $queue->enqueue(UdbMutation::dropBlock(UdbBlock::Ips));
        $sequence = UdbUnsignedDecimal::fromInt(1);
        $origin = new UdbStreamOrigin('002', $this->epoch());
        $queue->enqueue(UdbMutation::dropBlock(UdbBlock::Ips)->assigned($sequence, $origin));
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')->with(':002 DB * DRP ' . $this->epoch() . ' 1 I');
        $this->coordinator->tick($connection);
        self::assertSame(0, $this->coordinator->queuedMutationCount());
    }

    #[Test]
    public function duplicateQueuedAdmissionTriggersRecoveryRatherThanGrowing(): void
    {
        $this->makeReady();
        $round = UdbUnsignedDecimal::fromInt(99);
        new ReflectionProperty(UdbSessionCoordinator::class, 'manifestRoundId')->setValue($this->coordinator, $round);
        new ReflectionProperty(UdbSessionCoordinator::class, 'manifestRoundDeadline')->setValue($this->coordinator, $this->clock->now() + 60);
        new ReflectionMethod(UdbSessionCoordinator::class, 'enqueueRes')->invoke($this->coordinator, UdbBlock::Ips, $round);
        $transfers = new ReflectionProperty(UdbSessionCoordinator::class, 'transfers')->getValue($this->coordinator);
        self::assertInstanceOf(UdbOutboundTransferTracker::class, $transfers);
        $transfers->track(UdbBlock::Nicks, UdbUnsignedDecimal::fromInt(99), 'tx1', str_repeat('a', 64), UdbUnsignedDecimal::fromInt(0), $this->clock->now());
        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 99, block: UdbBlock::Ips));
        self::assertContains(':002 DB 001 ERR RES 4 99 I', $this->written);
    }

    protected function setUp(): void
    {
        parent::setUp();
        UdbStagingSchemaFixture::create($this->entityManager->getConnection());
        $this->blockStates = new FakeBlockStates();
        $this->records = new FakeUdbRecords();
        $this->snapshots = new FakeUdbSnapshots(['I' => ['1.2.3.4::clones' => '*5']]);

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
            mutations: $this->records,
        );
    }

    private function coordinatorWithLogger(LoggerInterface $logger): UdbSessionCoordinator
    {
        return new UdbSessionCoordinator(
            '002',
            $this->blockStates,
            $this->snapshots,
            $logger,
            new UdbOclgView(),
            scheduler: $this->scheduler,
            clock: $this->clock,
            mutations: $this->records,
        );
    }

    private function bootstrapCoordinator(
        UdbWireTakeover $takeover,
        ?LoggerInterface $logger = null,
        ?UdbAuthorityStateRepositoryInterface $authority = null,
        ?UdbRecordMutationStoreInterface $mutations = null,
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
            mutations: $mutations,
        );
    }

    private function createWireTakeover(LoggerInterface $logger = new NullLogger()): UdbWireTakeover
    {
        return new UdbWireTakeover(
            new FakeUdbRecords(),
            new FakeBlockStates(),
            $this->entityManager,
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
            new UdbChannelSuspendReasonResolver($this->createStub(TranslatorInterface::class), 'ChanServ'),
        );
    }

    /**
     * @return list<array{0: UdbBlock, 1: string, 2: string}>
     */
    private function bootstrapRecords(): array
    {
        return [
            [UdbBlock::Nicks, 'alice::vhost', 'a.example'],
            [UdbBlock::Channels, '#chan::topic', 'welcome'],
            [UdbBlock::Ips, '1.2.3.4::clones', '*5'],
            [UdbBlock::Settings, 'propagator', 'hub1.example'],
            [UdbBlock::Links, 'hub1.example::options', '*1'],
            [UdbBlock::Lines, 'G::*@bad.example::reason', 'reason'],
        ];
    }

    private function inventoryFrame(UdbBlock $block, string $digest, int $roundId, int $count, string $rootSid = '001', int $watermark = 0): UdbFrame
    {
        return new UdbFrame(
            UdbFrameKind::Inf,
            '001',
            '002',
            roundId: $roundId,
            block: $block,
            checksum: $digest,
            count: $count,
            watermark: $watermark,
            rootSid: $rootSid,
            rootEpoch: self::PEER_EPOCH,
        );
    }

    private function committedMutation(UdbSessionCoordinator $coordinator, string $block, string $path, ?string $value): UdbMutation
    {
        $epoch = new ReflectionProperty(UdbSessionCoordinator::class, 'epoch')->getValue($coordinator);
        $current = new ReflectionProperty(UdbSessionCoordinator::class, 'mutationSequence')->getValue($coordinator);
        self::assertIsString($epoch);
        self::assertInstanceOf(UdbUnsignedDecimal::class, $current);
        $sequence = $current->increment();
        self::assertNotNull($sequence);

        if ($this->snapshots instanceof FakeUdbSnapshots) {
            $this->snapshots->setWatermark((int) (string) $sequence);
        }

        return UdbTestValues::mutation($block, $path, $value)->assigned($sequence, new UdbStreamOrigin('002', $epoch));
    }

    private function stageBootstrapRecord(
        UdbSessionCoordinator $coordinator,
        ConnectionInterface $connection,
        UdbBlock $block,
        string $path,
        string $logicalValue,
        string $rootSid = '001',
        int $watermark = 0,
    ): void {
        $digest = UdbTestValues::digest($block, [$path => $logicalValue]);
        $coordinator->handleFrame($this->inventoryFrame($block, $digest, 1, 1, $rootSid, $watermark), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1, block: $block, txid: 'tx', checksum: $digest, watermark: $watermark), $connection);
        $coordinator->handleFrame(new UdbFrame(
            UdbFrameKind::Put,
            '001',
            '002',
            roundId: 1,
            block: $block,
            txid: 'tx',
            path: $path,
            value: $logicalValue,
            typedValue: UdbTestValues::fromLogical($block, $path, $logicalValue),
        ), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::End, '001', '002', roundId: 1, block: $block, txid: 'tx', checksum: $digest, watermark: $watermark), $connection);
    }

    /** @return list<string> Latest writes from callback-driven transport dispatch. */
    private function capturedWrites(): array
    {
        return $this->written;
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
        $this->coordinator->onStoreInitialized();
        $this->coordinator->setOwnName(self::OWN_NAME);
        $this->coordinator->onRemoteServer('001', 'ircd.example.net');
        $this->coordinator->onLinkReady($this->connection);
    }

    private function seedStore(): void
    {
        foreach (UdbBlock::all() as $block) {
            $this->blockStates->upsert($block->letter(), UdbChecksum::empty($block), 0);
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

    private function useSmallMutationQueue(): void
    {
        $this->coordinator = new UdbSessionCoordinator(
            '002',
            $this->blockStates,
            $this->snapshots,
            scheduler: $this->scheduler,
            clock: $this->clock,
            mutationQueue: new UdbMutationQueue(2),
        );
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
}
