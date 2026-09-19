<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Session;

use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbAuthorityStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordMutationStoreInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Projection\Oclg\UdbOclgView;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbReconciliationRound;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbRoundTimeout;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbSnapshotProviderInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutation;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutationQueue;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbWireTakeover;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbWireTakeoverOutcome;
use App\Irc\Adapter\Protocol\UnrealUdb\Takeover\UdbWireTakeoverOutcomeKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Transfer\UdbOutboundTransferTracker;
use App\Irc\Adapter\Protocol\UnrealUdb\Transfer\UdbTransferAcknowledgement;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrameKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbWireCodec;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbWireLogRedactor;
use App\Irc\Adapter\Runtime\LoopSchedulerInterface;
use App\Irc\Adapter\Runtime\RevoltLoopScheduler;
use App\Irc\Adapter\Runtime\SessionEventPump;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function bin2hex;
use function count;
use function in_array;
use function is_int;
use function max;
use function min;
use function random_bytes;
use function sprintf;
use function strtoupper;
use function substr;

/**
 * Single authority for the UnrealUdb S2S session state machine.
 *
 * Services are ALWAYS the UDB authority: the HEL announces our own server
 * name, and once the peer selects us a reconciliation
 * round offers all six blocks from the authoritative store. A second HEL is
 * written right after the inventory as an ordered TCP barrier: when its ACK
 * arrives the peer has processed every INF, so any divergent block has
 * produced a RES. Authority readiness requires that barrier plus a confirmed
 * ACK for every staged snapshot served.
 *
 * Inbound staged snapshots and real-time mutations are rejected: the peer is
 * downstream. Timeouts and protocol errors re-offer a fresh reconciliation
 * round instead of releasing queued mutations; queued mutations are never
 * lost because the authoritative store already holds them and snapshots
 * recover any divergence.
 */
final class UdbSessionCoordinator implements UdbSessionStateInterface, UdbSessionController, UdbStoreInitializationListener
{
    private const int ROUND_INACTIVITY_TIMEOUT = 60;

    private const int ERR_REOFFER_BUDGET = 6;

    /** @var list<string> */
    private const array HEL_CAPABILITIES = ['OCL', 'OCLG'];

    private ?ConnectionInterface $connection = null;

    private ?bool $storeReady = null;

    private UdbUnsignedDecimal $roundSequence;

    private int $txidSequence = 0;

    private UdbUnsignedDecimal $errorCorrelation;

    /** Last sequence allocated in this Ares authority epoch, shared by all blocks. */
    private UdbUnsignedDecimal $mutationSequence;

    private int $errReofferCount = 0;

    private ?bool $approved = null;

    /** True when queue loss is not yet covered by an in-flight full snapshot round. */
    private bool $resyncPending = false;

    /** The full reconciliation round currently covering a queue-overflow debt. */
    private ?UdbUnsignedDecimal $recoveryRoundId = null;

    private readonly string $epoch;

    private readonly UdbOclgView $oclgView;

    private readonly UdbPeerSession $peer;

    private readonly UdbHelloBarrier $helloBarrier;

    private readonly UdbReconciliationRound $reconciliation;

    private readonly UdbOutboundTransferTracker $transfers;

    private readonly UdbMutationQueue $mutationQueue;

    private readonly UdbClock $clock;

    /** @var array<string, array<string, string>> */
    private array $roundSnapshotRecords = [];

    /** @var array<string, string> */
    private array $roundSnapshotDigests = [];

    private ?UdbUnsignedDecimal $roundSnapshotWatermark = null;

    public function __construct(
        private readonly string $sid,
        private readonly UdbBlockStateRepositoryInterface $blockStates,
        private readonly UdbSnapshotProviderInterface $snapshots,
        private readonly LoggerInterface $logger = new NullLogger(),
        UdbOclgView $oclgView = new UdbOclgView(),
        private readonly ?UdbWireTakeover $wireTakeover = null,
        private readonly ?UdbAuthorityStateRepositoryInterface $authority = null,
        private readonly LoopSchedulerInterface $scheduler = new RevoltLoopScheduler(),
        ?UdbClock $clock = null,
        ?UdbPeerSession $peer = null,
        ?UdbHelloBarrier $helloBarrier = null,
        ?UdbReconciliationRound $reconciliation = null,
        ?UdbOutboundTransferTracker $transfers = null,
        ?UdbMutationQueue $mutationQueue = null,
        private readonly ?UdbRecordMutationStoreInterface $mutations = null,
    ) {
        $this->epoch = bin2hex(random_bytes(8));
        $this->oclgView = $oclgView;
        $this->clock = $clock ?? new SystemUdbClock();
        $this->peer = $peer ?? new UdbPeerSession();
        $this->helloBarrier = $helloBarrier ?? new UdbHelloBarrier();
        $this->reconciliation = $reconciliation ?? new UdbReconciliationRound();
        $this->transfers = $transfers ?? new UdbOutboundTransferTracker();
        $this->mutationQueue = $mutationQueue ?? new UdbMutationQueue();
        $this->roundSequence = UdbUnsignedDecimal::fromInt(0);
        $this->errorCorrelation = UdbUnsignedDecimal::fromInt(0);
        $this->mutationSequence = UdbUnsignedDecimal::fromInt(0);
    }

    private ?SessionEventPump $eventPump = null;

    private ?string $deadlineWatcherId = null;

    private int $deadlineGeneration = 0;

    public function setEventPump(?SessionEventPump $eventPump): void
    {
        $this->eventPump = $eventPump;
    }

    public function getDeadlineWatcherId(): ?string
    {
        return $this->deadlineWatcherId;
    }

    public function setOwnName(string $ownName): void
    {
        $this->peer->setOwnName($ownName);
    }

    public function onRemoteServer(string $sid, string $serverName): void
    {
        $this->peer->captureRemote($sid, $serverName);
    }

    public function getRemoteServerName(): ?string
    {
        return $this->peer->remoteServerName();
    }

    public function activeRoundId(): ?int
    {
        return $this->reconciliation->id()?->toInt();
    }

    public function epoch(): string
    {
        return $this->epoch;
    }

    public function queuedMutationCount(): int
    {
        return $this->mutationQueue->count();
    }

    /** Called when our burst completes: the HEL must go out now. */
    public function onLinkReady(ConnectionInterface $connection): void
    {
        $this->connection = $connection;
        $this->sendHel();
        $this->scheduleNextDeadlineTimer();
    }

    /** Called once the authoritative store finished (re)initializing. */
    public function onStoreInitialized(): void
    {
        $this->storeReady = null;
        $this->maybeOfferReconciliation();
    }

    /** Cheap deadline sweep invoked on every handled line or timer callback. */
    public function tick(?ConnectionInterface $connection = null): void
    {
        if (null !== $connection) {
            $this->connection = $connection;
        }

        if (null === $this->connection) {
            return;
        }

        $now = $this->clock->now();
        if ($this->wireTakeover?->expire()) {
            $this->logger->warning('UDB wire takeover round timed out; requesting a fresh inventory.');
            $this->retryWireBootstrap();

            return;
        }
        if ($this->oclgView->expire()) {
            $this->logger->warning('UDB OCLG stage timed out.');
        }
        $expiredTransfer = $this->transfers->firstExpired($now);
        if (null !== $expiredTransfer) {
            $this->logger->warning('UDB staged transfer ACK timed out; re-offering reconciliation.', $expiredTransfer);
            $this->reofferWithinBudget();
            $this->scheduleNextDeadlineTimer();

            return;
        }

        $roundTimeout = $this->reconciliation->timeoutAt($now);
        if (UdbRoundTimeout::None !== $roundTimeout) {
            $this->logger->warning('UDB reconciliation round timed out; re-offering.', [
                'timeout' => $roundTimeout->name,
                'round' => $this->reconciliation->id(),
            ]);
            $this->reofferWithinBudget();
            $this->scheduleNextDeadlineTimer();

            return;
        }

        $helloDeadline = $this->helloBarrier->deadline();
        if (null !== $helloDeadline && !$this->reconciliation->isActive() && $now >= $helloDeadline) {
            $this->logger->error('UDB HEL acknowledgement timed out; disconnecting unsupported peer.');
            $connection = $this->connection;
            $this->reset();
            $connection->disconnect();

            return;
        }

        $this->flushMutations();
        $this->scheduleNextDeadlineTimer();
    }

    /** Drops all volatile state. Called on connection loss. */
    public function reset(): void
    {
        $this->restoreRecoveryDebt();
        $this->cancelDeadlineTimer();
        $this->peer->reset();
        $this->helloBarrier->reset();
        $this->storeReady = null;
        $this->reconciliation->reset();
        $this->transfers->reset();
        $this->clearRoundSnapshot();
        $this->errReofferCount = 0;
        $this->approved = null;
        $this->oclgView->reset();
        $this->wireTakeover?->reset();
        $this->connection = null;
        // $this->mutationQueue is preserved: the store already holds every
        // queued change and the next reconciliation round recovers delivery.
    }

    public function isResyncPending(): bool
    {
        return $this->resyncPending || null !== $this->recoveryRoundId;
    }

    public function scheduleNextDeadlineTimer(): void
    {
        $this->cancelDeadlineTimer();

        if (null === $this->connection) {
            return;
        }

        $deadlines = array_filter([
            $this->helloBarrier->deadline(),
            $this->reconciliation->nextDeadline(),
            $this->transfers->nextDeadline(),
            $this->wireTakeover?->nextDeadline(),
            $this->oclgView->nextDeadline(),
        ], static fn (?int $deadline): bool => null !== $deadline);
        $earliestDeadline = [] !== $deadlines ? min($deadlines) : null;

        if (null === $earliestDeadline) {
            return;
        }

        $delaySeconds = max(0.01, $earliestDeadline - $this->clock->now());

        $generation = $this->deadlineGeneration;
        $this->deadlineWatcherId = $this->scheduler->delay($delaySeconds, function () use ($generation): void {
            if ($generation !== $this->deadlineGeneration) {
                return;
            }
            $this->deadlineWatcherId = null;
            if (null !== $this->eventPump) {
                if ($this->eventPump->getQueueSize() >= SessionEventPump::READER_HIGH_WATER) {
                    $this->scheduleNextDeadlineTimer();

                    return;
                }

                // A new generation must keep its real FIFO position behind
                // frames already queued; stale generations are cheap no-ops.
                $this->eventPump->enqueue(function () use ($generation): void {
                    if ($generation === $this->deadlineGeneration) {
                        $this->tick();
                    }
                });
            } else {
                $this->tick();
            }
        });
    }

    private function cancelDeadlineTimer(): void
    {
        ++$this->deadlineGeneration;
        if (null !== $this->deadlineWatcherId) {
            $this->scheduler->cancel($this->deadlineWatcherId);
            $this->deadlineWatcherId = null;
        }
    }

    public function handleFrame(UdbFrame $frame, ConnectionInterface $connection): void
    {
        $this->connection = $connection;

        if (!$this->mayProcessBeforeHello($frame) && !$this->helloBarrier->isConfirmed()) {
            $this->logger->debug('Ignoring UDB frame before HEL confirmation.', ['kind' => $frame->kind->value]);

            return;
        }

        match ($frame->kind) {
            UdbFrameKind::HelAck => $this->handleHelAck($frame),
            UdbFrameKind::Hel => $this->handleHel($frame),
            UdbFrameKind::Inf => $this->handleInf($frame),
            UdbFrameKind::Res => $this->handleRes($frame),
            UdbFrameKind::Begin, UdbFrameKind::Put, UdbFrameKind::End => $this->handleInboundStaged($frame),
            UdbFrameKind::Ack => $this->handleAck($frame),
            UdbFrameKind::Err => $this->handleErr($frame),
            UdbFrameKind::Ins, UdbFrameKind::Del, UdbFrameKind::Drp => $this->handleForbiddenMutation($frame),
            UdbFrameKind::Exp => $this->handleExpiryRequest($frame),
            UdbFrameKind::ManifestReq => $this->handleManifestRequest($frame),
            UdbFrameKind::ManifestAck => null,
            UdbFrameKind::OclgBegin => $this->handleOclgBegin($frame),
            UdbFrameKind::OclgItem => $this->handleOclgItem($frame),
            UdbFrameKind::OclgEnd => $this->handleOclgEnd($frame),
        };

        $this->settleRecoveryRound();
        $this->maybeOfferPendingReconciliation();

        if ($this->isAuthorityReady()) {
            $this->flushMutations();
        }
    }

    public function isAuthorityReady(): bool
    {
        return !$this->isWireBootstrapActive()
            && $this->helloBarrier->isConfirmed()
            && $this->peer->isAuthorized()
            && $this->isStoreReady()
            && !$this->helloBarrier->hasPending()
            && $this->reconciliation->isCompleted()
            && $this->transfers->isEmpty();
    }

    public function enqueueMutation(UdbMutation $mutation): void
    {
        $sequence = $this->mutationSequence->increment();
        if (null === $sequence) {
            $this->logger->error('UDB mutation sequence exhausted for the current epoch; a daemon restart is required.');
            $this->resyncPending = true;

            return;
        }
        $this->mutationSequence = $sequence;

        if ($this->mutationQueue->enqueue($mutation->sequenced($sequence))) {
            $this->logger->warning('UDB mutation queue overflow; recovering divergence via reconciliation.', [
                'block' => $mutation->block,
            ]);
            $this->resyncPending = true;
            $this->maybeOfferPendingReconciliation();
        }

        if ($this->isAuthorityReady() && null !== $this->eventPump) {
            try {
                $this->eventPump->enqueueCoalesced('udb-mutation-flush', function (): void {
                    $this->flushMutations();
                });
            } catch (LogicException) {
            }
        }
    }

    /** True only for classes in the last complete READY OCLG projection. */
    public function isOperclassGloballyAvailable(string $operclass): bool
    {
        return $this->oclgView->isOperclassGloballyAvailable($operclass);
    }

    public function getAvailableOperclasses(): array
    {
        return $this->oclgView->getAvailableOperclasses();
    }

    private function handleHelAck(UdbFrame $frame): void
    {
        if (!$this->isDirectPeerFrame($frame)) {
            $this->logger->warning('Ignoring UDB HEL ACK from an unexpected peer.');

            return;
        }

        $change = $this->peer->observeAdvertisement($frame, $this->isWireBootstrapActive());
        if (UdbPeerAdvertisementChange::Invalid === $change) {
            $this->logger->warning('Ignoring malformed UDB HEL ACK advertisement.');

            return;
        }
        if (UdbPeerAdvertisementChange::NewInstance === $change) {
            $this->resetPeerInstanceState();
            $this->oclgView->expectEpoch($frame->epoch ?? '');
            $this->sendHel(force: true);
            $this->scheduleNextDeadlineTimer();

            return;
        }
        $this->oclgView->expectEpoch($frame->epoch ?? '');

        $hadActiveRound = $this->reconciliation->isActive();
        $barrierTicket = $this->helloBarrier->acknowledge();
        if (null === $barrierTicket) {
            $this->logger->debug('Ignoring duplicate UDB HEL ACK without a pending HEL.');

            return;
        }

        if ($hadActiveRound) {
            $this->reconciliation->acknowledgeBarrier($barrierTicket, $this->clock->now());
            $this->completeReconciliationIfSettled();
        }
        $this->scheduleNextDeadlineTimer();
        $this->logger->debug('UDB HEL 4 confirmed by peer.');

        // Only the FIRST ack (or a newly authorized peer) triggers an offer:
        // barrier acks of completed rounds must not loop new rounds.
        if (!$hadActiveRound) {
            $this->maybeOfferReconciliation();
        }
    }

    private function handleHel(UdbFrame $frame): void
    {
        if (!$this->isDirectPeerFrame($frame)) {
            $this->logger->warning('Ignoring UDB HEL from an unexpected peer.');

            return;
        }

        $change = $this->peer->observeAdvertisement($frame, $this->isWireBootstrapActive());
        if (UdbPeerAdvertisementChange::Invalid === $change) {
            $this->logger->warning('Ignoring malformed UDB HEL advertisement.');

            return;
        }
        if (UdbPeerAdvertisementChange::NewInstance === $change) {
            $this->resetPeerInstanceState();
        }
        $this->oclgView->expectEpoch($frame->epoch ?? '');

        $this->logger->info('UDB HEL 4 received from peer.', [
            'peer' => $frame->sourceSid,
            'propagator' => $frame->propagator,
            'authorizes_us' => $this->peer->isAuthorized(),
        ]);

        // Upstream sends its own HEL request before the ACK. The peer replays
        // its OCLG view while processing our ACK and only afterwards emits the
        // ACK for our request, so this order keeps the peer ACK (which opens
        // our capability gate) ahead of its first OCLG burst.
        if (!$this->helloBarrier->hasPending() && !$this->helloBarrier->isConfirmed()) {
            $this->sendHel();
        }

        $ownName = $this->peer->ownName();
        if (null !== $ownName && '' !== $ownName) {
            $advertised = $this->isWireBootstrapActive() ? '?' : $ownName;
            $this->write(UdbWireCodec::helAck($this->sid, $frame->sourceSid, $advertised, $this->epoch, self::HEL_CAPABILITIES));
        }

        // Bootstrap requests the peer inventory with our HEL; approved sessions
        // may instead offer the authoritative local inventory.
        if (!$this->isWireBootstrapActive()) {
            $this->maybeOfferReconciliation();
        }
        $this->scheduleNextDeadlineTimer();
    }

    private function maybeOfferReconciliation(): void
    {
        if ($this->isWireBootstrapActive() || !$this->helloBarrier->isConfirmed() || !$this->peer->isAuthorized() || !$this->isStoreReady()) {
            return;
        }

        if (!$this->transfers->isEmpty() || $this->reconciliation->isActive() || $this->helloBarrier->hasPending()) {
            return;
        }

        $this->offerReconciliation();
    }

    /** Offers our authoritative snapshot: one INF per block plus a barrier HEL. */
    private function offerReconciliation(): void
    {
        if ($this->isWireBootstrapActive() || !$this->helloBarrier->isConfirmed() || !$this->peer->isAuthorized()) {
            return;
        }

        $remoteSid = $this->peer->remoteSid();
        if (null === $remoteSid
            || null === $this->connection
            || !$this->isStoreReady()
            || !$this->transfers->isEmpty()
            || $this->reconciliation->isActive()
            || $this->helloBarrier->hasPending()
        ) {
            return;
        }

        $now = $this->clock->now();
        $nextSequence = $this->roundSequence->incrementNonZero();
        $clockSequence = UdbUnsignedDecimal::fromInt($now);
        $roundId = 0 <= $clockSequence->compare($nextSequence) ? $clockSequence : $nextSequence;
        $this->roundSequence = $roundId;
        $this->reconciliation->start($roundId, $now);
        $this->captureRoundSnapshot();
        if ($this->resyncPending) {
            $this->recoveryRoundId = $roundId;
            $this->resyncPending = false;
        }

        foreach (UdbBlock::all() as $block) {
            $records = $this->roundSnapshotRecords[$block->letter()];
            $this->write(UdbWireCodec::inf(
                $this->sid,
                $remoteSid,
                $roundId,
                $block,
                $this->roundSnapshotDigests[$block->letter()],
                count($records),
                $now,
                $this->roundSnapshotWatermark,
            ));
        }

        // A second HEL right after the inventory acts as an ordered TCP
        // barrier: its ACK proves the peer processed every INF frame, so all
        // RES responses for divergent blocks are already inbound.
        $barrierTicket = $this->sendHel(force: true);
        if (null !== $barrierTicket) {
            $this->reconciliation->expectBarrier($barrierTicket);
        }
        $this->scheduleNextDeadlineTimer();

        $this->logger->info('Offered UDB reconciliation round to peer.', ['round' => $roundId]);
    }

    private function handleInf(UdbFrame $frame): void
    {
        if (!$this->isDirectPeerFrame($frame)) {
            return;
        }
        if (!$this->isWireBootstrapActive()) {
            $this->logger->debug('Ignoring peer INF: services are the UDB authority.', [
                'block' => $frame->block?->letter(),
            ]);

            return;
        }

        $this->writeTakeoverOutcome($this->wireTakeover?->accept($frame) ?? UdbWireTakeoverOutcome::ignored(), $frame->sourceSid);
        $this->scheduleNextDeadlineTimer();
    }

    private function handleRes(UdbFrame $frame): void
    {
        $block = $frame->block;
        $roundId = $frame->roundId;
        if (null === $block || null === $roundId) {
            $this->logger->debug('Ignoring RES for unknown UDB block or missing roundId.');

            return;
        }

        if (!$this->isDirectPeerFrame($frame)) {
            return;
        }

        if ($this->isWireBootstrapActive()
            || !$this->helloBarrier->isConfirmed()
            || !$this->peer->isAuthorized()
            || !$this->isStoreReady()
        ) {
            $this->write(UdbWireCodec::err($this->sid, $frame->sourceSid, 'RES', 6, $roundId, $block));

            return;
        }

        if ($this->transfers->has($block)) {
            $this->write(UdbWireCodec::err($this->sid, $frame->sourceSid, 'RES', 4, $roundId, $block));

            return;
        }

        if (!$this->reconciliation->acceptRes($roundId, $block, $this->clock->now())) {
            $this->write(UdbWireCodec::err($this->sid, $frame->sourceSid, 'RES', 5, $roundId, $block));

            return;
        }

        $this->serveBlock($block, $roundId);
    }

    /** Serves one block as a staged snapshot (BEGIN / PUT ... / END). */
    private function serveBlock(UdbBlock $block, int|UdbUnsignedDecimal $roundId): void
    {
        $roundId = is_int($roundId) ? UdbUnsignedDecimal::fromInt($roundId) : $roundId;
        $remoteSid = $this->peer->remoteSid();
        if (null === $remoteSid) {
            return;
        }

        $records = $this->roundSnapshotRecords[$block->letter()] ?? $this->snapshots->recordsForBlock($block);
        $digest = $this->roundSnapshotDigests[$block->letter()] ?? UdbChecksum::fromRecords(self::tuples($records));
        $watermark = $this->roundSnapshotWatermark ?? $this->mutationSequence;
        ++$this->txidSequence;
        $txid = sprintf('%08x', $this->txidSequence);

        foreach ($records as $path => $value) {
            if (!UdbPathCodec::fitsLimits($path, $value)) {
                $this->logger->error('Refusing to serve oversized UDB snapshot record.', [
                    'block' => $block->letter(),
                    'path' => $path,
                ]);
                $this->write(UdbWireCodec::err($this->sid, $remoteSid, 'PUT', 3, $roundId, $block));
                // acceptRes() already consumed this block for the current
                // round. Keeping the round alive would let the HEL barrier
                // complete without a staged transfer ACK and could expose a
                // false-ready session. Abandon every round-scoped transfer
                // and publish a fresh inventory instead.
                $this->reofferWithinBudget();

                return;
            }
        }

        if (!$this->transfers->track($block, $roundId, $txid, $digest, $watermark, $this->clock->now())) {
            return;
        }

        $this->write(UdbWireCodec::begin($this->sid, $remoteSid, $roundId, $block, $txid, $digest, $watermark));
        foreach ($records as $path => $value) {
            $this->write(UdbWireCodec::put($this->sid, $remoteSid, $roundId, $block, $txid, $path, $value));
        }
        $this->write(UdbWireCodec::end($this->sid, $remoteSid, $roundId, $block, $txid, $digest, $watermark));
        $this->scheduleNextDeadlineTimer();
    }

    /** Staged snapshots are downstream traffic — except during the wire bootstrap. */
    private function handleInboundStaged(UdbFrame $frame): void
    {
        if (!$this->isDirectPeerFrame($frame)) {
            $this->logger->warning('Ignoring staged UDB frame from an unexpected peer.', [
                'kind' => $frame->kind->value,
                'source' => $frame->sourceSid,
            ]);

            return;
        }

        if ($this->isWireBootstrapActive()) {
            $this->acceptWireStaged($frame);

            return;
        }

        $this->logger->warning('Rejected inbound staged snapshot: services are the sole UDB authority.', [
            'kind' => $frame->kind->value,
            'block' => $frame->block?->letter(),
            'round' => $frame->roundId,
        ]);

        $this->write(UdbWireCodec::err(
            $this->sid,
            $frame->sourceSid,
            $frame->kind->value,
            6,
            $frame->roundId ?? UdbUnsignedDecimal::fromInt(0),
            $frame->block,
        ));
    }

    private function acceptWireStaged(UdbFrame $frame): void
    {
        $outcome = $this->wireTakeover?->accept($frame) ?? UdbWireTakeoverOutcome::ignored();
        $this->writeTakeoverOutcome($outcome, $frame->sourceSid);
        $this->scheduleNextDeadlineTimer();

        if (null !== $this->wireTakeover && $this->wireTakeover->isComplete()) {
            $this->completeWireBootstrap();
        }
    }

    private function writeTakeoverOutcome(UdbWireTakeoverOutcome $outcome, string $targetSid): void
    {
        if (UdbWireTakeoverOutcomeKind::Request === $outcome->kind && null !== $outcome->block && null !== $outcome->roundId) {
            $this->write(UdbWireCodec::res($this->sid, $targetSid, $outcome->roundId, $outcome->block));
        } elseif (UdbWireTakeoverOutcomeKind::Acknowledge === $outcome->kind && null !== $outcome->block && null !== $outcome->roundId && null !== $outcome->txid && null !== $outcome->digest && null !== $outcome->watermark) {
            $this->write(UdbWireCodec::ack($this->sid, $targetSid, $outcome->roundId, $outcome->block, $outcome->txid, $outcome->digest, $outcome->watermark));
        } elseif (UdbWireTakeoverOutcomeKind::Error === $outcome->kind && null !== $outcome->block && null !== $outcome->roundId && null !== $outcome->subcommand && null !== $outcome->errorCode) {
            $this->write(UdbWireCodec::err($this->sid, $targetSid, $outcome->subcommand, $outcome->errorCode, $outcome->roundId, $outcome->block));
        }
    }

    private function retryWireBootstrap(bool $reset = true): void
    {
        if (!$this->isWireBootstrapActive()) {
            return;
        }
        if ($reset) {
            $this->wireTakeover?->reset();
        }
        $this->sendHel(force: true);
        $this->scheduleNextDeadlineTimer();
    }

    /** Applies the adopted generation, approves the authority and renegotiates as the FQDN authority. */
    private function completeWireBootstrap(): void
    {
        if (null === $this->wireTakeover || null === $this->authority) {
            return;
        }

        try {
            $fingerprint = $this->wireTakeover->finalize();
            $this->authority->approve($fingerprint);
            $this->approved = true;
        } catch (Throwable $e) {
            $this->logger->error('UDB wire bootstrap finalization failed; the store stays unapproved.', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->storeReady = null;
        $this->logger->info('UDB wire bootstrap complete: services adopted the peer dataset and are now the authority.', [
            'fingerprint' => $fingerprint,
        ]);

        // Announce ourselves as the FQDN propagator; the peer re-ACKs and
        // reconciliation serves any remaining divergence (SQL-backed N/C/K).
        $this->sendHel(force: true);
        $this->maybeOfferReconciliation();
        $this->scheduleNextDeadlineTimer();
    }

    private function isWireBootstrapActive(): bool
    {
        if (null === $this->wireTakeover || null === $this->authority) {
            return false;
        }

        $this->approved ??= $this->authority->isApproved();

        // Bootstrap is active only while the store has never been approved.
        return !$this->approved;
    }

    private function handleAck(UdbFrame $frame): void
    {
        if (!$this->isDirectPeerFrame($frame)) {
            return;
        }

        $result = $this->transfers->acknowledge($frame);
        if (UdbTransferAcknowledgement::Unknown === $result) {
            return;
        }
        if (UdbTransferAcknowledgement::Mismatched === $result) {
            $this->logger->warning('Ignoring UDB staged ACK with mismatched round, txid or digest.', [
                'block' => $frame->block?->letter(),
                'round' => $frame->roundId,
            ]);

            return;
        }

        if (null !== $frame->block && null !== $frame->roundId) {
            $this->reconciliation->acknowledgeTransfer($frame->roundId, $frame->block, $this->clock->now());
        }
        $this->completeReconciliationIfSettled();
        $this->scheduleNextDeadlineTimer();
        $this->errReofferCount = 0;
        $this->logger->debug('Staged transfer acknowledged by peer.', ['block' => $frame->block?->letter()]);
    }

    /**
     * Re-offers a reconciliation round unless the recovery budget is
     * exhausted (persistent peer rejections or dead links would otherwise
     * loop forever). Any confirmed staged ACK resets the budget.
     */
    private function reofferWithinBudget(): void
    {
        $this->restoreRecoveryDebt();
        $this->reconciliation->reset();
        $this->transfers->reset();
        $this->clearRoundSnapshot();
        $this->helloBarrier->abandonPending();
        if (++$this->errReofferCount > self::ERR_REOFFER_BUDGET) {
            $this->logger->error('UDB reconciliation re-offer budget exhausted; waiting for a new peer HEL.');

            return;
        }

        $this->maybeOfferReconciliation();
    }

    private function handleErr(UdbFrame $frame): void
    {
        if (!$this->isDirectPeerFrame($frame)) {
            return;
        }

        $this->logger->warning('UDB peer reported an error.', [
            'subcommand' => $frame->subcommand,
            'code' => $frame->errorCode,
            'round' => $frame->roundId,
            'block' => $frame->block?->letter(),
        ]);

        $affectsRound = in_array($frame->subcommand, ['INF', 'RES', 'BEGIN', 'PUT', 'END'], true)
            && null !== $this->reconciliation->id()
            && null !== $frame->roundId
            && $frame->roundId->equals($this->reconciliation->id());

        if (!$affectsRound) {
            return;
        }

        $this->reofferWithinBudget();
    }

    private function handleForbiddenMutation(UdbFrame $frame): void
    {
        if (!$this->peer->isBroadcastFromPeer($frame)) {
            return;
        }

        $this->logger->warning('Ignored forbidden mutation from peer (services are the sole authority).', [
            'kind' => $frame->kind->value,
            'path' => $frame->path,
            'block' => $frame->block?->letter(),
        ]);

        $this->errorCorrelation = $this->errorCorrelation->incrementNonZero();

        $letter = $frame->block?->letter();
        if (null === $letter && null !== $frame->path && '' !== $frame->path) {
            $letter = UdbBlock::fromLetter(strtoupper($frame->path[0]))?->letter();
        }

        $block = null !== $letter ? UdbBlock::fromLetter($letter) : null;
        $this->write(UdbWireCodec::err($this->sid, $frame->sourceSid, $frame->kind->value, 6, $this->errorCorrelation, $block));
    }

    /** Responds to downstream anti-entropy from one coherent store view. */
    private function handleManifestRequest(UdbFrame $frame): void
    {
        if (!$this->isDirectPeerFrame($frame) || null === $frame->roundId) {
            return;
        }
        if (!$this->peer->isAuthorized() || !$this->isStoreReady()) {
            $this->write(UdbWireCodec::err($this->sid, $frame->sourceSid, 'MANIFEST', 6, $frame->roundId, null));

            return;
        }

        $watermark = $this->mutationSequence;
        foreach (UdbBlock::all() as $block) {
            $records = $this->snapshots->recordsForBlock($block);
            $this->write(UdbWireCodec::manifestAck(
                $this->sid,
                $frame->sourceSid,
                $frame->roundId,
                $block,
                count($records),
                UdbChecksum::fromRecords(self::tuples($records)),
                $watermark,
            ));
        }
    }

    /** Ares is the root authority: EXP is an exact expiry compare-and-delete. */
    private function handleExpiryRequest(UdbFrame $frame): void
    {
        if (!$this->isDirectPeerFrame($frame) || null === $frame->path || null === $frame->expectedExpires) {
            return;
        }
        if (!$this->peer->isAuthorized() || !$this->isStoreReady() || null === $this->mutations) {
            $this->errorCorrelation = $this->errorCorrelation->incrementNonZero();
            $this->write(UdbWireCodec::err($this->sid, $frame->sourceSid, 'EXP', 6, $this->errorCorrelation, UdbBlock::Lines));

            return;
        }

        $path = substr($frame->path, 3);
        try {
            if ($this->mutations->expireLineWithManifest($path, $frame->expectedExpires, $this->clock->now())) {
                $this->enqueueMutation(new UdbMutation(UdbBlock::Lines->letter(), $path, null));
            }
        } catch (Throwable $exception) {
            $this->errorCorrelation = $this->errorCorrelation->incrementNonZero();
            $this->logger->error('UDB EXP compare-and-delete failed.', ['path' => $frame->path, 'error' => $exception->getMessage()]);
            $this->write(UdbWireCodec::err($this->sid, $frame->sourceSid, 'EXP', 3, $this->errorCorrelation, UdbBlock::Lines));
        }
    }

    private function handleOclgBegin(UdbFrame $frame): void
    {
        if (!$this->isDirectPeerFrame($frame)) {
            return;
        }

        $this->oclgView->begin($frame);
        $this->scheduleNextDeadlineTimer();
    }

    private function handleOclgItem(UdbFrame $frame): void
    {
        if (!$this->isDirectPeerFrame($frame)) {
            return;
        }

        $this->oclgView->item($frame);
    }

    private function handleOclgEnd(UdbFrame $frame): void
    {
        if (!$this->isDirectPeerFrame($frame)) {
            return;
        }

        $this->oclgView->end($frame);
        $this->scheduleNextDeadlineTimer();
    }

    /** True when every UDB block has been initialized in the authoritative store. */
    private function isStoreReady(): bool
    {
        if (null === $this->storeReady) {
            $missing = array_diff(
                array_map(static fn (UdbBlock $block): string => $block->letter(), UdbBlock::all()),
                array_keys($this->blockStates->all()),
            );
            $this->storeReady = [] === $missing;
        }

        return $this->storeReady;
    }

    /**
     * Sends our HEL announcing ourselves as the propagator. Every HEL we
     * send is ACKed exactly once (in TCP order), so counting sent HELs and
     * received ACKs implements the inventory barrier.
     */
    private function sendHel(bool $force = false): ?int
    {
        if (!$force && ($this->helloBarrier->hasPending() || $this->helloBarrier->isConfirmed())) {
            return null;
        }

        $remoteSid = $this->peer->remoteSid();
        if (null === $remoteSid) {
            return null;
        }

        $ownName = $this->peer->ownName();
        if (null === $ownName || '' === $ownName) {
            return null;
        }

        $propagator = $this->isWireBootstrapActive() ? '?' : $ownName;
        $this->write(UdbWireCodec::hel($this->sid, $remoteSid, $propagator, $this->epoch, self::HEL_CAPABILITIES));

        return $this->helloBarrier->sent($this->clock->now(), self::ROUND_INACTIVITY_TIMEOUT);
    }

    private function isDirectPeerFrame(UdbFrame $frame): bool
    {
        return $this->peer->isDirectFromPeer($frame, $this->sid);
    }

    /** HEL and HEL ACK are the only DB frames accepted before the capability gate. */
    private function mayProcessBeforeHello(UdbFrame $frame): bool
    {
        return UdbFrameKind::Hel === $frame->kind
            || UdbFrameKind::HelAck === $frame->kind;
    }

    private function flushMutations(): void
    {
        if (!$this->isAuthorityReady() || null === $this->connection) {
            return;
        }

        foreach ($this->mutationQueue->drain() as $mutation) {
            if (null === $mutation->sequence) {
                continue;
            }
            if (null === $mutation->value) {
                $this->write(UdbWireCodec::del($this->sid, $this->epoch, $mutation->sequence, $mutation->block, $mutation->encodedPath));
            } else {
                $this->write(UdbWireCodec::ins($this->sid, $this->epoch, $mutation->sequence, $mutation->block, $mutation->encodedPath, $mutation->value));
            }
        }
    }

    /** Remote epoch changes invalidate every instance-scoped state machine. */
    private function resetPeerInstanceState(): void
    {
        $this->restoreRecoveryDebt();
        $this->cancelDeadlineTimer();
        $this->helloBarrier->reset();
        $this->reconciliation->reset();
        $this->transfers->reset();
        $this->clearRoundSnapshot();
        $this->errReofferCount = 0;
        $this->oclgView->reset();
        $this->wireTakeover?->reset();
    }

    private function maybeOfferPendingReconciliation(): void
    {
        if ($this->resyncPending) {
            $this->maybeOfferReconciliation();
        }
    }

    private function settleRecoveryRound(): void
    {
        if (null === $this->recoveryRoundId || $this->reconciliation->isActive()) {
            return;
        }

        $this->recoveryRoundId = null;
    }

    private function restoreRecoveryDebt(): void
    {
        if (null !== $this->recoveryRoundId) {
            $this->resyncPending = true;
            $this->recoveryRoundId = null;
        }
    }

    private function captureRoundSnapshot(): void
    {
        $this->roundSnapshotRecords = [];
        $this->roundSnapshotDigests = [];
        $this->roundSnapshotWatermark = $this->mutationSequence;
        foreach (UdbBlock::all() as $block) {
            $records = $this->snapshots->recordsForBlock($block);
            $this->roundSnapshotRecords[$block->letter()] = $records;
            $this->roundSnapshotDigests[$block->letter()] = UdbChecksum::fromRecords(self::tuples($records));
        }
    }

    private function clearRoundSnapshot(): void
    {
        $this->roundSnapshotRecords = [];
        $this->roundSnapshotDigests = [];
        $this->roundSnapshotWatermark = null;
    }

    private function completeReconciliationIfSettled(): void
    {
        if (!$this->reconciliation->completeIfSettled($this->transfers->isEmpty())) {
            return;
        }
        if (null !== $this->roundSnapshotWatermark) {
            $this->mutationQueue->discardThrough($this->roundSnapshotWatermark);
        }
        $this->clearRoundSnapshot();
    }

    /**
     * Converts a path => value map into the [path, value] tuple list the
     * checksum builder consumes.
     *
     * @param array<string, string> $records
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function tuples(array $records): array
    {
        $tuples = [];
        foreach ($records as $path => $value) {
            $tuples[] = [$path, $value];
        }

        return $tuples;
    }

    private function write(string $line): void
    {
        if (null === $this->connection) {
            $this->logger->warning('Cannot write UDB frame without an active connection.');

            return;
        }

        $this->connection->writeLine($line);
        // Snapshot PUT and live INS frames may contain password material or
        // encryption keys; the redactor masks those values while the rest of
        // the frame stays visible in the log.
        $this->logger->debug('> ' . UdbWireLogRedactor::redact($line));
    }
}
