<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\UdbMutation;
use App\Domain\Udb\Repository\UdbAuthorityStateRepositoryInterface;
use App\Domain\Udb\Repository\UdbBlockStateRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbChecksum;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrame;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrameKind;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbPathCodec;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbWireCodec;
use App\Infrastructure\IRC\Runtime\LoopSchedulerInterface;
use App\Infrastructure\IRC\Runtime\RevoltLoopScheduler;
use App\Infrastructure\IRC\Runtime\SessionEventPump;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_diff;
use function array_keys;
use function array_map;
use function array_shift;
use function bin2hex;
use function count;
use function in_array;
use function random_bytes;
use function sprintf;
use function strcasecmp;
use function strtoupper;
use function time;

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
class UdbSessionCoordinator implements UdbSessionStateInterface
{
    private const int ROUND_INACTIVITY_TIMEOUT = 60;

    private const int TRANSFER_TIMEOUT = 60;

    private const int MUTATION_QUEUE_LIMIT = 1024;

    private const int ERR_REOFFER_BUDGET = 3;

    /** @var list<string> */
    private const array HEL_CAPABILITIES = ['OCL', 'OCLG'];

    private ?ConnectionInterface $connection = null;

    private ?string $ownName = null;

    private ?string $remoteServerName = null;

    public function getRemoteServerName(): ?string
    {
        return $this->remoteServerName;
    }

    private ?string $remoteSid = null;

    private bool $ownHelAcked = false;

    private bool $peerAuthorized = false;

    private ?bool $storeReady = null;

    private int $helSentCount = 0;

    private int $helAcksReceived = 0;

    private ?int $barrierDeadline = null;

    private ?int $activeRoundId = null;

    private int $roundSequence = 0;

    private int $txidSequence = 0;

    private int $errorCorrelation = 0;

    private int $errReofferCount = 0;

    private ?bool $approved = null;

    private readonly string $epoch;

    /** @var array<string, array{txid: string, roundId: int, digest: string, deadline: int}> Outgoing staged transfers awaiting ACK. */
    private array $outstanding = [];

    /** @var list<UdbMutation> */
    private array $mutationQueue = [];

    private readonly UdbOclgView $oclgView;

    public function __construct(
        private readonly string $sid,
        private readonly UdbBlockStateRepositoryInterface $blockStates,
        private readonly UdbSnapshotProviderInterface $snapshots,
        private readonly LoggerInterface $logger = new NullLogger(),
        UdbOclgView $oclgView = new UdbOclgView(),
        private readonly ?UdbWireTakeover $wireTakeover = null,
        private readonly ?UdbAuthorityStateRepositoryInterface $authority = null,
        private readonly LoopSchedulerInterface $scheduler = new RevoltLoopScheduler(),
    ) {
        $this->epoch = bin2hex(random_bytes(8));
        $this->oclgView = $oclgView;
    }

    private ?SessionEventPump $eventPump = null;

    private ?string $deadlineWatcherId = null;

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
        $this->ownName = $ownName;
    }

    public function onRemoteServer(string $sid, string $serverName): void
    {
        $this->remoteSid = $sid;
        $this->remoteServerName = $serverName;
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

        $now = time();
        foreach ($this->outstanding as $letter => $info) {
            if ($info['deadline'] <= $now) {
                $this->logger->warning('UDB staged transfer ACK timed out; re-offering reconciliation.', [
                    'block' => $letter,
                    'round' => $info['roundId'],
                ]);
                $this->reofferWithinBudget();
                $this->scheduleNextDeadlineTimer();

                return;
            }
        }

        if (null !== $this->barrierDeadline
            && $this->helSentCount > $this->helAcksReceived
            && $this->barrierDeadline <= $now
        ) {
            $this->logger->warning('UDB reconciliation barrier ACK timed out; re-offering.');
            $this->reofferWithinBudget();
            $this->scheduleNextDeadlineTimer();

            return;
        }

        $this->flushMutations();
        $this->scheduleNextDeadlineTimer();
    }

    /** Drops all volatile state. Called on connection loss. */
    public function reset(): void
    {
        $this->cancelDeadlineTimer();
        $this->remoteServerName = null;
        $this->remoteSid = null;
        $this->ownHelAcked = false;
        $this->peerAuthorized = false;
        $this->storeReady = null;
        $this->helSentCount = 0;
        $this->helAcksReceived = 0;
        $this->barrierDeadline = null;
        $this->activeRoundId = null;
        $this->errReofferCount = 0;
        $this->outstanding = [];
        $this->approved = null;
        $this->oclgView->reset();
        $this->wireTakeover?->reset();
        $this->connection = null;
        // $this->mutationQueue is preserved: the store already holds every
        // queued change and the next reconciliation round recovers delivery.
    }

    public function scheduleNextDeadlineTimer(): void
    {
        $this->cancelDeadlineTimer();

        if (null === $this->connection) {
            return;
        }

        $earliestDeadline = null;
        if (null !== $this->barrierDeadline && $this->helSentCount > $this->helAcksReceived) {
            $earliestDeadline = (float) $this->barrierDeadline;
        }

        foreach ($this->outstanding as $info) {
            $d = (float) $info['deadline'];
            if (null === $earliestDeadline || $d < $earliestDeadline) {
                $earliestDeadline = $d;
            }
        }

        if (null === $earliestDeadline) {
            return;
        }

        $delaySeconds = max(0.01, $earliestDeadline - time());

        $this->deadlineWatcherId = $this->scheduler->delay($delaySeconds, function (): void {
            $this->deadlineWatcherId = null;
            if (null !== $this->eventPump) {
                $this->eventPump->enqueue(function (): void {
                    $this->tick();
                });
            } else {
                $this->tick();
            }
        });
    }

    private function cancelDeadlineTimer(): void
    {
        if (null !== $this->deadlineWatcherId) {
            $this->scheduler->cancel($this->deadlineWatcherId);
            $this->deadlineWatcherId = null;
        }
    }

    public function handleFrame(UdbFrame $frame, ConnectionInterface $connection): void
    {
        $this->connection = $connection;

        match ($frame->kind) {
            UdbFrameKind::HelAck => $this->handleHelAck($frame),
            UdbFrameKind::Hel => $this->handleHel($frame),
            UdbFrameKind::Inf => $this->logger->debug('Ignoring peer INF: services are the UDB authority.', [
                'block' => $frame->block?->letter(),
            ]),
            UdbFrameKind::Res => $this->handleRes($frame),
            UdbFrameKind::Begin, UdbFrameKind::Put, UdbFrameKind::End => $this->handleInboundStaged($frame),
            UdbFrameKind::Ack => $this->handleAck($frame),
            UdbFrameKind::Err => $this->handleErr($frame),
            UdbFrameKind::Ins, UdbFrameKind::Del, UdbFrameKind::Drp, UdbFrameKind::Opt => $this->handleForbiddenMutation($frame),
            UdbFrameKind::OclgBegin => $this->handleOclgBegin($frame),
            UdbFrameKind::OclgItem => $this->handleOclgItem($frame),
            UdbFrameKind::OclgEnd => $this->handleOclgEnd($frame),
        };

        if ($this->isAuthorityReady()) {
            $this->flushMutations();
        }
    }

    public function isAuthorityReady(): bool
    {
        return $this->ownHelAcked
            && $this->peerAuthorized
            && $this->isStoreReady()
            && $this->helAcksReceived >= $this->helSentCount
            && [] === $this->outstanding;
    }

    public function enqueueMutation(UdbMutation $mutation): void
    {
        if (count($this->mutationQueue) >= self::MUTATION_QUEUE_LIMIT) {
            array_shift($this->mutationQueue);
            $this->logger->warning('UDB mutation queue overflow; recovering divergence via reconciliation.', [
                'block' => $mutation->block,
            ]);
            $this->offerReconciliation();
        }

        $this->mutationQueue[] = $mutation;

        if ($this->isAuthorityReady() && null !== $this->eventPump) {
            $this->eventPump->enqueue(function (): void {
                $this->flushMutations();
            });
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

        $firstAck = !$this->ownHelAcked;
        ++$this->helAcksReceived;
        $this->ownHelAcked = true;
        $this->barrierDeadline = null;
        $this->scheduleNextDeadlineTimer();
        $this->logger->debug('UDB HEL 4 confirmed by peer.', [
            'acks' => $this->helAcksReceived,
            'sent' => $this->helSentCount,
        ]);

        // Only the FIRST ack (or a newly authorized peer) triggers an offer:
        // barrier acks of completed rounds must not loop new rounds.
        if ($firstAck) {
            $this->maybeOfferReconciliation();
        }
    }

    private function handleHel(UdbFrame $frame): void
    {
        if (!$this->isDirectPeerFrame($frame)) {
            $this->logger->warning('Ignoring UDB HEL from an unexpected peer.');

            return;
        }

        $propagator = $frame->propagator ?? '';
        // During the wire bootstrap the direct peer is the exclusive
        // bootstrap peer selected by our `?` advertisement.
        $this->peerAuthorized = $this->isWireBootstrapActive()
            || (null !== $this->ownName && '' !== $this->ownName && 0 === strcasecmp($propagator, $this->ownName));

        $this->logger->info('UDB HEL 4 received from peer.', [
            'peer' => $frame->sourceSid,
            'propagator' => $propagator,
            'authorizes_us' => $this->peerAuthorized,
        ]);

        if (null !== $this->ownName && '' !== $this->ownName) {
            $advertised = $this->isWireBootstrapActive() ? '?' : $this->ownName;
            $this->write(UdbWireCodec::helAck($this->sid, $frame->sourceSid, $advertised, $this->epoch, self::HEL_CAPABILITIES));
        }

        if (0 === $this->helSentCount) {
            $this->sendHel();
        }

        // A repeated authorized selection is also an explicit retry trigger.
        $this->maybeOfferReconciliation();
    }

    private function maybeOfferReconciliation(): void
    {
        if (!$this->ownHelAcked || !$this->peerAuthorized || !$this->isStoreReady()) {
            return;
        }

        if ([] !== $this->outstanding) {
            return;
        }

        // The barrier ACK of a completed round also lands here; only offer
        // when there is no round in flight yet.
        if (null !== $this->activeRoundId && $this->helSentCount > $this->helAcksReceived) {
            return;
        }

        $this->offerReconciliation();
    }

    /** Offers our authoritative snapshot: one INF per block plus a barrier HEL. */
    private function offerReconciliation(): void
    {
        if (!$this->ownHelAcked || !$this->peerAuthorized) {
            return;
        }

        $remoteSid = $this->remoteSid;
        if (null === $remoteSid || null === $this->connection || !$this->isStoreReady()) {
            return;
        }

        ++$this->roundSequence;
        $roundId = time() + $this->roundSequence;
        $this->activeRoundId = $roundId;
        $this->outstanding = [];

        foreach (UdbBlock::all() as $block) {
            $this->write(UdbWireCodec::inf(
                $this->sid,
                $remoteSid,
                $roundId,
                $block,
                $this->snapshots->checksumForBlock($block),
                time(),
            ));
        }

        // A second HEL right after the inventory acts as an ordered TCP
        // barrier: its ACK proves the peer processed every INF frame, so all
        // RES responses for divergent blocks are already inbound.
        $this->sendHel(force: true);
        $this->barrierDeadline = time() + self::ROUND_INACTIVITY_TIMEOUT;
        $this->scheduleNextDeadlineTimer();

        $this->logger->info('Offered UDB reconciliation round to peer.', ['round' => $roundId]);
    }

    private function handleRes(UdbFrame $frame): void
    {
        $block = $frame->block;
        $roundId = $frame->roundId;
        if (null === $block || null === $roundId) {
            $this->logger->debug('Ignoring RES for unknown UDB block or missing roundId.');

            return;
        }

        if (!$this->ownHelAcked || !$this->peerAuthorized || !$this->isStoreReady()) {
            $this->write(UdbWireCodec::err($this->sid, $frame->sourceSid, 'RES', 6, $roundId, $block));

            return;
        }

        $this->serveBlock($block, $roundId);
    }

    /** Serves one block as a staged snapshot (BEGIN / PUT ... / END). */
    private function serveBlock(UdbBlock $block, int $roundId): void
    {
        $remoteSid = $this->remoteSid;
        if (null === $remoteSid) {
            return;
        }

        $records = $this->snapshots->recordsForBlock($block);
        $digest = UdbChecksum::fromRecords(self::tuples($records));
        ++$this->txidSequence;
        $txid = sprintf('%08x', $this->txidSequence);

        $this->write(UdbWireCodec::begin($this->sid, $remoteSid, $roundId, $block, $txid, $digest));

        foreach ($records as $path => $value) {
            if (!UdbPathCodec::fitsLimits($path, $value)) {
                $this->logger->error('Refusing to serve oversized UDB snapshot record.', [
                    'block' => $block->letter(),
                    'path' => $path,
                ]);
                $this->write(UdbWireCodec::err($this->sid, $remoteSid, 'PUT', 3, $roundId, $block));

                return;
            }

            $this->write(UdbWireCodec::put($this->sid, $remoteSid, $roundId, $block, $txid, $path, $value));
        }

        $this->write(UdbWireCodec::end($this->sid, $remoteSid, $roundId, $block, $txid, $digest));

        $this->outstanding[$block->letter()] = [
            'txid' => $txid,
            'roundId' => $roundId,
            'digest' => $digest,
            'deadline' => time() + self::TRANSFER_TIMEOUT,
        ];
        $this->scheduleNextDeadlineTimer();
    }

    /** Staged snapshots are downstream traffic — except during the wire bootstrap. */
    private function handleInboundStaged(UdbFrame $frame): void
    {
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
            $frame->roundId ?? 0,
            $frame->block,
        ));
    }

    private function acceptWireStaged(UdbFrame $frame): void
    {
        $completed = $this->wireTakeover?->accept($frame);
        if (null !== $completed) {
            $this->write(UdbWireCodec::ack(
                $this->sid,
                $frame->sourceSid,
                $completed['roundId'],
                $completed['block'],
                $completed['txid'],
                $completed['digest'],
            ));
        }

        if (null !== $this->wireTakeover && $this->wireTakeover->isComplete()) {
            $this->completeWireBootstrap();
        }
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
        $letter = $frame->block?->letter();
        $info = null !== $letter ? ($this->outstanding[$letter] ?? null) : null;

        if (null === $info) {
            return;
        }

        if ($info['roundId'] !== $frame->roundId
            || $info['txid'] !== ($frame->txid ?? '')
            || $info['digest'] !== ($frame->checksum ?? '')
        ) {
            $this->logger->warning('Ignoring UDB staged ACK with mismatched round, txid or digest.', [
                'block' => $letter,
                'expectedRound' => $info['roundId'],
                'gotRound' => $frame->roundId,
            ]);

            return;
        }

        unset($this->outstanding[$letter]);
        $this->scheduleNextDeadlineTimer();
        $this->errReofferCount = 0;
        $this->logger->debug('Staged transfer acknowledged by peer.', ['block' => $letter]);
    }

    /**
     * Re-offers a reconciliation round unless the recovery budget is
     * exhausted (persistent peer rejections or dead links would otherwise
     * loop forever). Any confirmed staged ACK resets the budget.
     */
    private function reofferWithinBudget(): void
    {
        if (++$this->errReofferCount > self::ERR_REOFFER_BUDGET) {
            $this->logger->error('UDB reconciliation re-offer budget exhausted; waiting for a new peer HEL.');

            return;
        }

        $this->offerReconciliation();
    }

    private function handleErr(UdbFrame $frame): void
    {
        $this->logger->warning('UDB peer reported an error.', [
            'subcommand' => $frame->subcommand,
            'code' => $frame->errorCode,
            'round' => $frame->roundId,
            'block' => $frame->block?->letter(),
        ]);

        $affectsRound = in_array($frame->subcommand, ['INF', 'RES', 'BEGIN', 'PUT', 'END'], true)
            && null !== $this->activeRoundId
            && $frame->roundId === $this->activeRoundId;

        if (!$affectsRound) {
            return;
        }

        // The round is consumed by the FIRST error so the peer's remaining
        // ERR frames for the same round cannot amplify into new offers.
        $this->activeRoundId = null;

        $this->reofferWithinBudget();
    }

    private function handleForbiddenMutation(UdbFrame $frame): void
    {
        $this->logger->warning('Ignored forbidden mutation from peer (services are the sole authority).', [
            'kind' => $frame->kind->value,
            'path' => $frame->path,
            'block' => $frame->block?->letter(),
        ]);

        ++$this->errorCorrelation;

        $letter = $frame->block?->letter();
        if (null === $letter && null !== $frame->path && '' !== $frame->path) {
            $letter = UdbBlock::fromLetter(strtoupper($frame->path[0]))?->letter();
        }

        $block = null !== $letter ? UdbBlock::fromLetter($letter) : null;
        $this->write(UdbWireCodec::err($this->sid, $frame->sourceSid, $frame->kind->value, 6, $this->errorCorrelation, $block));
    }

    private function handleOclgBegin(UdbFrame $frame): void
    {
        if (!$this->isDirectPeerFrame($frame)) {
            return;
        }

        $this->oclgView->begin($frame);
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
    private function sendHel(bool $force = false): void
    {
        if (!$force && 0 !== $this->helSentCount) {
            return;
        }

        if (null === $this->remoteSid) {
            return;
        }

        if (null === $this->ownName || '' === $this->ownName) {
            return;
        }

        $propagator = $this->isWireBootstrapActive() ? '?' : $this->ownName;
        $this->write(UdbWireCodec::hel($this->sid, $this->remoteSid, $propagator, $this->epoch, self::HEL_CAPABILITIES));
        ++$this->helSentCount;
    }

    private function isDirectPeerFrame(UdbFrame $frame): bool
    {
        return null !== $this->remoteSid
            && $frame->sourceSid === $this->remoteSid
            && $frame->target === $this->sid;
    }

    private function flushMutations(): void
    {
        if (!$this->isAuthorityReady() || null === $this->connection) {
            return;
        }

        while ([] !== $this->mutationQueue) {
            $mutation = array_shift($this->mutationQueue);

            if (null === $mutation->value) {
                $this->write(UdbWireCodec::del($this->sid, $mutation->block, $mutation->encodedPath));
            } else {
                $this->write(UdbWireCodec::ins($this->sid, $mutation->block, $mutation->encodedPath, $mutation->value));
            }
        }
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
        $this->logger->debug('> ' . $line);
    }
}
