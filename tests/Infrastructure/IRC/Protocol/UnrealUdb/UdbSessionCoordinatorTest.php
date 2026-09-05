<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\UdbMutation;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\Udb\Repository\UdbAuthorityStateRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbChecksum;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrame;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrameKind;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbOclgViewDigest;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbOclgView;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbRecordExporter;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSessionCoordinator;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSnapshotProviderInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbWireTakeover;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

use function array_slice;
use function count;

#[CoversClass(UdbSessionCoordinator::class)]
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

        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: '?'));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '001', '002'));

        self::assertFalse($this->coordinator->isAuthorityReady());
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
            $this->helRequest(),
            $this->helAck(),
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
        self::assertSame($this->helRequest(), $this->written[6]);
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
        self::assertSame([$this->helAck()], array_slice($this->written, $count));
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

        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 7, epoch: '0123456789abcdef', status: 'BROKEN', count: 1, checksum: str_repeat('a', 64)));

        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function duplicateOclgItemAbortsTheStage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $this->coordinator = new UdbSessionCoordinator('002', $this->blockStates, $this->snapshots, $logger, new UdbOclgView($logger));
        $this->prepareLink();

        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 7, epoch: '0123456789abcdef', status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 7, epoch: '0123456789abcdef', path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 7, epoch: '0123456789abcdef', path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 7, epoch: '0123456789abcdef'));

        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function oclgEndForAnotherGenerationIsIgnored(): void
    {
        $this->prepareLink();

        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 7, epoch: '0123456789abcdef', status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 7, epoch: '0123456789abcdef', path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 99, epoch: '0123456789abcdef'));

        // The stage is still pending: nothing was committed or withdrawn.
        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));
    }

    #[Test]
    public function oclgReadySnapshotIsCommittedAtomically(): void
    {
        $this->prepareLink();
        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 7, epoch: '0123456789abcdef', status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));

        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 7, epoch: '0123456789abcdef', path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 7, epoch: '0123456789abcdef'));

        self::assertTrue($this->coordinator->isOperclassGloballyAvailable('netadmin'));
        self::assertSame(['netadmin'], $this->coordinator->getAvailableOperclasses());
    }

    #[Test]
    public function invalidOrIncompleteOclgSnapshotsWithdrawAvailability(): void
    {
        $this->prepareLink();
        $entries = ['netadmin' => str_repeat('a', 64)];
        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 7, epoch: '0123456789abcdef', status: 'READY', count: 1, checksum: UdbOclgViewDigest::fromEntries(true, $entries)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 7, epoch: '0123456789abcdef', path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 7, epoch: '0123456789abcdef'));
        self::assertTrue($this->coordinator->isOperclassGloballyAvailable('netadmin'));
        self::assertSame(['netadmin'], $this->coordinator->getAvailableOperclasses());

        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 8, epoch: '0123456789abcdef', status: 'INCOMPLETE', count: 0, checksum: UdbOclgViewDigest::fromEntries(false, [])));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 8, epoch: '0123456789abcdef'));
        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));
        self::assertSame([], $this->coordinator->getAvailableOperclasses());

        $this->handle(new UdbFrame(UdbFrameKind::OclgBegin, '001', '002', roundId: 9, epoch: '0123456789abcdef', status: 'READY', count: 1, checksum: str_repeat('b', 64)));
        $this->handle(new UdbFrame(UdbFrameKind::OclgItem, '001', '002', roundId: 9, epoch: '0123456789abcdef', path: 'netadmin', checksum: $entries['netadmin']));
        $this->handle(new UdbFrame(UdbFrameKind::OclgEnd, '001', '002', roundId: 9, epoch: '0123456789abcdef'));
        self::assertFalse($this->coordinator->isOperclassGloballyAvailable('netadmin'));
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
        self::assertSame([$this->helRequest()], $this->written);
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
            $this->helAck(),
            $this->helRequest(),
        ], $this->written);
    }

    #[Test]
    public function peerHelWhileTransfersAreOutstandingDoesNotOffer(): void
    {
        $this->makeReady();
        $this->handle(new UdbFrame(UdbFrameKind::Res, '001', '002', roundId: 20, block: UdbBlock::Ips));

        $this->written = [];
        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME));

        self::assertSame([$this->helAck()], $this->written);
    }

    #[Test]
    public function helFromAnUnexpectedPeerCannotAuthorizeUsOrReceiveAnAck(): void
    {
        $this->seedStore();
        $this->prepareLink();
        $this->written = [];

        $this->handle(new UdbFrame(UdbFrameKind::Hel, '999', '002', propagator: self::OWN_NAME));
        $this->handle(new UdbFrame(UdbFrameKind::Hel, '001', '999', propagator: self::OWN_NAME));
        $this->handle(new UdbFrame(UdbFrameKind::HelAck, '999', '002'));

        self::assertSame([], $this->written);
        self::assertFalse($this->coordinator->isAuthorityReady());
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
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::HelAck, '001', '002'), $connection);
        $this->written = [];

        // The peer stages one block; the END is validated and ACKed.
        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: $digest), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Put, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', path: '1.2.3.4::clones', value: '*5'), $connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::End, '001', '002', roundId: 1, block: UdbBlock::Ips, txid: 'tx1', checksum: $digest), $connection);

        self::assertMatchesRegularExpression('/^:002 DB 001 ACK 1 I tx1 ' . $digest . '$/', $this->written[0] ?? '');
    }

    #[Test]
    public function approvedAuthorityRejectsStagedTrafficAgain(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $takeover = $this->createWireTakeover($logger);
        $coordinator = $this->bootstrapCoordinator($takeover, $logger);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        // Simulate an approved authority (no pending bootstrap).
        new ReflectionClass(UdbSessionCoordinator::class)->getProperty('approved')->setValue($coordinator, true);

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
        );
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::HelAck, '001', '002'), $connection);
        // The peer announces itself (its HEL authorizes the bootstrap exchange).
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME), $connection);
        $this->written = [];

        // Stage every block over the wire (SQL-owned blocks are discarded).
        foreach ($this->bootstrapRecords() as [$block, $path, $value]) {
            $digest = UdbChecksum::fromRecords([[$path, $value]]);
            $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Begin, '001', '002', roundId: 1, block: $block, txid: 'tx', checksum: $digest), $connection);
            $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Put, '001', '002', roundId: 1, block: $block, txid: 'tx', path: $path, value: $value), $connection);
            $coordinator->handleFrame(new UdbFrame(UdbFrameKind::End, '001', '002', roundId: 1, block: $block, txid: 'tx', checksum: $digest), $connection);
        }

        // The last block completed the bootstrap: authority approved, FQDN
        // HEL renegotiation and the reconciliation round are on the wire.
        $lines = implode("\n", $this->written);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 services\.example\.net [0-9a-f]{16} OCL OCLG$/m', $lines);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ N [0-9A-F]{8} \d+$/m', $lines);
        self::assertMatchesRegularExpression('/^:002 DB 001 INF \d+ K /m', $lines);
    }

    #[Test]
    public function wireBootstrapFinalizationFailureKeepsTheStoreUnapproved(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error');
        $takeover = $this->createWireTakeover();
        $coordinator = $this->bootstrapCoordinator($takeover, $logger);
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        $authority = new ReflectionClass(UdbSessionCoordinator::class)->getProperty('authority')->getValue($coordinator);
        $authority->method('approve')->willThrowException(new RuntimeException('Database down.'));

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });
        $coordinator->onLinkReady($connection);
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::HelAck, '001', '002'), $connection);
        $this->written = [];

        // Complete a full six-block exchange with a broken authority store.
        foreach ($this->bootstrapRecords() as [$block, $path, $value]) {
            $digest = UdbChecksum::fromRecords([[$path, $value]]);
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
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: '-'), $connection);
        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 ACK ' . preg_quote(self::OWN_NAME, '/') . ' [0-9a-f]{16} OCL OCLG$/', $this->written[0]);
        $this->written = [];

        // 3. Peer sends HEL selecting our FQDN: authorizes us, HEL ACK announces our FQDN
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::Hel, '001', '002', propagator: self::OWN_NAME), $connection);
        self::assertCount(1, $this->written);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 ACK ' . preg_quote(self::OWN_NAME, '/') . ' [0-9a-f]{16} OCL OCLG$/', $this->written[0]);
        $this->written = [];

        // Seed store so reconciliation can proceed
        foreach (UdbBlock::all() as $block) {
            $this->blockStates->upsert($block->letter(), '00000000');
        }

        // 4. Peer ACKs our HEL: reconciliation round starts, TCP barrier HEL announces our FQDN (never '?')
        $coordinator->handleFrame(new UdbFrame(UdbFrameKind::HelAck, '001', '002'), $connection);
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
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 \? [0-9a-f]{16} OCL OCLG$/', $this->written[0]);

        $this->written = [];
        $coordinator->reset();
        $coordinator->setOwnName(self::OWN_NAME);
        $coordinator->onRemoteServer('001', 'ircd.example.net');

        // Second link: reset cleared cache, authority now returns true -> announces FQDN
        $coordinator->onLinkReady($connection);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 ' . preg_quote(self::OWN_NAME, '/') . ' [0-9a-f]{16} OCL OCLG$/', $this->written[0]);
    }

    private function bootstrapCoordinator(UdbWireTakeover $takeover, ?LoggerInterface $logger = null): UdbSessionCoordinator
    {
        $authority = $this->createStub(UdbAuthorityStateRepositoryInterface::class);
        $authority->method('isApproved')->willReturn(false);

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
        return new UdbWireTakeover(new FakeUdbRecords(), new FakeBlockStates(), $this->createStub(EntityManagerInterface::class), $this->createEmptyExporter(), $logger);
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

    /** Valid schema record for every block, shared by the bootstrap tests. @return list<array{0: UdbBlock, 1: string, 2: string}> */
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
        $epoch = new ReflectionClass(UdbSessionCoordinator::class)->getProperty('epoch')->getValue($this->coordinator);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $epoch);

        return $epoch;
    }
}
