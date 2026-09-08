<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Takeover;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\SystemUdbClock;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbClock;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrameKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_unique;
use function array_values;
use function assert;
use function count;
use function explode;
use function hash;
use function implode;
use function in_array;
use function strlen;

/** Wire bootstrap receiver for one peer inventory reconciliation round. */
final class UdbWireTakeover
{
    private const int MAX_STAGE_RECORDS = 500000;

    private const int MAX_STAGE_BYTES = 67108864;

    private const int INACTIVITY_TIMEOUT = 60;

    private const int ABSOLUTE_TIMEOUT = 300;

    /** @var list<UdbBlock> */
    private const array SQL_OWNED = [UdbBlock::Nicks, UdbBlock::Channels, UdbBlock::Lines];

    public int $maxStageRecords = self::MAX_STAGE_RECORDS;

    public int $maxStageBytes = self::MAX_STAGE_BYTES;

    /** @var array<string, array{txid: string, roundId: int, digest: string, entries: array<string, string>, bytes: int, inactivityDeadline: int, absoluteDeadline: int}> */
    private array $stages = [];

    /** @var list<string> */
    private array $completed = [];

    /** @var array<string, array{txid: string, roundId: int, digest: string}> */
    private array $completedTransfers = [];

    /** @var array<string, true> */
    private array $requestedBlocks = [];

    /** @var array<string, array<string, string>> */
    private array $imported = [];

    private ?int $roundId = null;

    private ?int $roundInactivityDeadline = null;

    private ?int $roundAbsoluteDeadline = null;

    public function __construct(
        private readonly UdbRecordRepositoryInterface $records,
        private readonly UdbBlockStateRepositoryInterface $blockStates,
        private readonly EntityManagerInterface $em,
        private readonly UdbRecordExporter $exporter,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly UdbClock $clock = new SystemUdbClock(),
    ) {}

    /** @return list<string> */
    public function completedBlocks(): array
    {
        return array_values(array_unique($this->completed));
    }

    public function isComplete(): bool
    {
        return count($this->completedBlocks()) >= count(UdbBlock::all());
    }

    public function reset(): void
    {
        $this->stages = [];
        $this->completed = [];
        $this->completedTransfers = [];
        $this->requestedBlocks = [];
        $this->imported = [];
        $this->roundId = null;
        $this->roundInactivityDeadline = null;
        $this->roundAbsoluteDeadline = null;
    }

    public function expire(): bool
    {
        $now = $this->clock->now();
        if ((null !== $this->roundInactivityDeadline && $now >= $this->roundInactivityDeadline)
            || (null !== $this->roundAbsoluteDeadline && $now >= $this->roundAbsoluteDeadline)
        ) {
            $this->reset();

            return true;
        }

        foreach ($this->stages as $stage) {
            if ($now >= $stage['inactivityDeadline'] || $now >= $stage['absoluteDeadline']) {
                $this->reset();

                return true;
            }
        }

        return false;
    }

    public function nextDeadline(): ?int
    {
        $deadlines = [$this->roundInactivityDeadline, $this->roundAbsoluteDeadline];
        foreach ($this->stages as $stage) {
            $deadlines[] = $stage['inactivityDeadline'];
            $deadlines[] = $stage['absoluteDeadline'];
        }

        $next = null;
        foreach ($deadlines as $deadline) {
            if (null !== $deadline && (null === $next || $deadline < $next)) {
                $next = $deadline;
            }
        }

        return $next;
    }

    public function accept(UdbFrame $frame): UdbWireTakeoverOutcome
    {
        $block = $frame->block;
        if (null === $block || null === $frame->roundId) {
            return UdbWireTakeoverOutcome::ignored();
        }

        return match ($frame->kind) {
            UdbFrameKind::Inf => $this->inventory($frame, $block),
            UdbFrameKind::Begin => $this->begin($frame, $block),
            UdbFrameKind::Put => $this->put($frame, $block),
            UdbFrameKind::End => $this->end($frame, $block),
            default => UdbWireTakeoverOutcome::ignored(),
        };
    }

    public function finalize(): string
    {
        if (!$this->isComplete()) {
            throw new LogicException('Cannot finalize an incomplete UDB wire takeover round.');
        }

        $this->em->wrapInTransaction(function (): void {
            foreach (UdbBlock::all() as $block) {
                if (in_array($block, self::SQL_OWNED, true)) {
                    $this->records->replaceBlock($block->letter(), $this->exporter->encodedBlockRecords($block));
                } else {
                    $this->records->replaceBlock($block->letter(), $this->imported[$block->letter()] ?? []);
                }
                $this->blockStates->upsert($block->letter(), $this->blockChecksum($block));
            }
        });
        $this->em->clear();

        return $this->fingerprint();
    }

    private function inventory(UdbFrame $frame, UdbBlock $block): UdbWireTakeoverOutcome
    {
        assert(null !== $frame->roundId);
        if (null === $this->roundId) {
            $now = $this->clock->now();
            $this->roundId = $frame->roundId;
            $this->roundInactivityDeadline = $now + self::INACTIVITY_TIMEOUT;
            $this->roundAbsoluteDeadline = $now + self::ABSOLUTE_TIMEOUT;
        } elseif ($this->roundId !== $frame->roundId) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'INF', 5);
        }

        $letter = $block->letter();
        if (isset($this->requestedBlocks[$letter])) {
            return UdbWireTakeoverOutcome::ignored();
        }

        $this->requestedBlocks[$letter] = true;
        $this->touchRound();

        return UdbWireTakeoverOutcome::request($block, $frame->roundId);
    }

    private function begin(UdbFrame $frame, UdbBlock $block): UdbWireTakeoverOutcome
    {
        assert(null !== $frame->roundId);
        if (null === $frame->txid) {
            return UdbWireTakeoverOutcome::ignored();
        }
        if ($this->roundId !== $frame->roundId || !isset($this->requestedBlocks[$block->letter()])) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'BEGIN', 5);
        }
        if (isset($this->completedTransfers[$block->letter()]) || isset($this->stages[$block->letter()])) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'BEGIN', 4);
        }

        $now = $this->clock->now();
        $this->stages[$block->letter()] = [
            'txid' => $frame->txid,
            'roundId' => $frame->roundId,
            'digest' => $frame->checksum ?? '',
            'entries' => [],
            'bytes' => 0,
            'inactivityDeadline' => $now + self::INACTIVITY_TIMEOUT,
            'absoluteDeadline' => $now + self::ABSOLUTE_TIMEOUT,
        ];
        $this->touchRound();

        return UdbWireTakeoverOutcome::ignored();
    }

    private function put(UdbFrame $frame, UdbBlock $block): UdbWireTakeoverOutcome
    {
        assert(null !== $frame->roundId);
        $stage = $this->stageFor($frame, $block);
        if (null === $stage || null === $frame->path || null === $frame->value) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'PUT', 5);
        }
        if (isset($stage['entries'][$frame->path])) {
            return $this->abortWithError($block, $frame->roundId, 'PUT', 'duplicate PUT path');
        }
        if (count($stage['entries']) >= $this->maxStageRecords) {
            return $this->abortWithError($block, $frame->roundId, 'PUT', 'record limit exhausted');
        }
        if (strlen($frame->path) + strlen($frame->value) > UdbPathCodec::RECORD_LINE_MAX || !UdbPathCodec::fitsLimits($frame->path, $frame->value)) {
            return $this->abortWithError($block, $frame->roundId, 'PUT', 'malformed or oversized PUT record');
        }

        $components = [];
        foreach (explode('::', $frame->path) as $component) {
            $decoded = UdbPathCodec::decodeComponent($component);
            assert(null !== $decoded);
            $components[] = $decoded;
        }
        if (!UdbSchema::validate($block, $components, $frame->value)) {
            return $this->abortWithError($block, $frame->roundId, 'PUT', 'schema-invalid PUT record');
        }

        $stage['entries'][$frame->path] = $frame->value;
        $stage['bytes'] += strlen($frame->path) + strlen($frame->value);
        $stage['inactivityDeadline'] = $this->clock->now() + self::INACTIVITY_TIMEOUT;
        $this->stages[$block->letter()] = $stage;
        if ($stage['bytes'] > $this->maxStageBytes) {
            return $this->abortWithError($block, $frame->roundId, 'PUT', 'byte limit exhausted');
        }
        $this->touchRound();

        return UdbWireTakeoverOutcome::ignored();
    }

    private function end(UdbFrame $frame, UdbBlock $block): UdbWireTakeoverOutcome
    {
        assert(null !== $frame->roundId);
        if (null === $frame->txid) {
            return UdbWireTakeoverOutcome::ignored();
        }
        $completed = $this->completedTransfers[$block->letter()] ?? null;
        if (null !== $completed) {
            if ($completed['roundId'] === $frame->roundId && $completed['txid'] === $frame->txid && $completed['digest'] === $frame->checksum) {
                return UdbWireTakeoverOutcome::acknowledge($block, $frame->roundId, $frame->txid, $completed['digest']);
            }

            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'END', 5);
        }

        $stage = $this->stageFor($frame, $block);
        if (null === $stage) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'END', 5);
        }
        unset($this->stages[$block->letter()]);

        $digest = UdbChecksum::fromRecords(self::tuples($stage['entries']));
        if (($frame->checksum ?? '') !== $digest) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'END', 3);
        }

        $this->completed[] = $block->letter();
        $this->completedTransfers[$block->letter()] = ['roundId' => $frame->roundId, 'txid' => $frame->txid, 'digest' => $digest];
        if (!in_array($block, self::SQL_OWNED, true)) {
            $this->imported[$block->letter()] = $stage['entries'];
        }
        $this->touchRound();

        return UdbWireTakeoverOutcome::acknowledge($block, $frame->roundId, $frame->txid, $digest);
    }

    /** @return array{txid: string, roundId: int, digest: string, entries: array<string, string>, bytes: int, inactivityDeadline: int, absoluteDeadline: int}|null */
    private function stageFor(UdbFrame $frame, UdbBlock $block): ?array
    {
        $stage = $this->stages[$block->letter()] ?? null;
        if (null === $stage || $stage['txid'] !== $frame->txid || $stage['roundId'] !== $frame->roundId) {
            return null;
        }
        $now = $this->clock->now();
        if ($now >= $stage['inactivityDeadline'] || $now >= $stage['absoluteDeadline']) {
            $this->reset();

            return null;
        }

        return $stage;
    }

    private function abortWithError(UdbBlock $block, int $roundId, string $subcommand, string $reason): UdbWireTakeoverOutcome
    {
        unset($this->stages[$block->letter()]);
        $this->logger->warning('UDB wire bootstrap: staged transfer discarded.', ['block' => $block->letter(), 'reason' => $reason]);

        return UdbWireTakeoverOutcome::error($block, $roundId, $subcommand, 2);
    }

    private function touchRound(): void
    {
        if (null !== $this->roundId) {
            $this->roundInactivityDeadline = $this->clock->now() + self::INACTIVITY_TIMEOUT;
        }
    }

    private function blockChecksum(UdbBlock $block): string
    {
        return UdbChecksum::fromRecords(self::tuples($this->records->recordsByBlock($block->letter())));
    }

    private function fingerprint(): string
    {
        $lines = [];
        foreach (UdbBlock::all() as $block) {
            $state = $this->blockStates->all()[$block->letter()] ?? null;
            $lines[] = $block->letter() . ':' . (null !== $state ? $state->getChecksum() : UdbChecksum::EMPTY);
        }

        return hash('sha256', implode("\n", $lines));
    }

    /** @param array<string, string> $records
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
}
