<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Domain\Udb\Repository\UdbBlockStateRepositoryInterface;
use App\Domain\Udb\Repository\UdbRecordRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbChecksum;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrame;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrameKind;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbPathCodec;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbSchema;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function assert;
use function count;
use function explode;
use function hash;
use function implode;
use function in_array;
use function strlen;

/**
 * Wire counterpart of UdbOfflineTakeover: adopts the dataset that the
 * exclusive bootstrap peer serves over the wire (services asked `?`).
 *
 * Ownership rule (fixed): SQL-backed blocks (N, C, K) are NEVER taken from
 * the peer — their staged transfers are validated, acknowledged and then
 * discarded, and rebuilt from the services SQL export at finalization.
 * Blocks Ares does not model (I, S, L) are imported from the IRCd snapshot.
 * Every block transfer is validated against the announced digest before it
 * is acknowledged; nothing is applied during the transfer (staging).
 */
final class UdbWireTakeover
{
    private const int MAX_STAGE_RECORDS = 500000;

    private const int MAX_STAGE_BYTES = 67108864; // 64 MB

    /** @var list<UdbBlock> blocks rebuilt from SQL instead of the peer snapshot */
    private const array SQL_OWNED = [UdbBlock::Nicks, UdbBlock::Channels, UdbBlock::Lines];

    /** Stage limits; overridable in tests via the public fields below. */
    public int $maxStageRecords = self::MAX_STAGE_RECORDS;

    public int $maxStageBytes = self::MAX_STAGE_BYTES;

    /** @var array<string, array{txid: string, roundId: int, digest: string, entries: array<string, string>, bytes: int}> */
    private array $stages = [];

    /** @var list<string> block letters with a validated (and acknowledged) transfer */
    private array $completed = [];

    /** @var array<string, array<string, string>> imported records per wire-imported block letter */
    private array $imported = [];

    public function __construct(
        private readonly UdbRecordRepositoryInterface $records,
        private readonly UdbBlockStateRepositoryInterface $blockStates,
        private readonly EntityManagerInterface $em,
        private readonly UdbRecordExporter $exporter,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** @return list<string> block letters with a validated transfer so far */
    public function completedBlocks(): array
    {
        return array_values(array_unique($this->completed));
    }

    public function isComplete(): bool
    {
        return count($this->completedBlocks()) >= count(UdbBlock::all());
    }

    /** Drops all volatile bootstrap state (connection loss / new session). */
    public function reset(): void
    {
        $this->stages = [];
        $this->completed = [];
        $this->imported = [];
    }

    /**
     * Handles one inbound staged frame. Returns the completed transfer when
     * the peer must receive an ACK, or null when the frame was ignored.
     *
     * @return array{block: UdbBlock, roundId: int, txid: string, digest: string}|null
     */
    public function accept(UdbFrame $frame): ?array
    {
        $block = $frame->block;
        if (null === $block || null === $frame->txid || null === $frame->roundId) {
            return null;
        }

        return match ($frame->kind) {
            UdbFrameKind::Begin => $this->begin($frame, $block),
            UdbFrameKind::Put => $this->put($frame, $block),
            UdbFrameKind::End => $this->end($frame, $block),
            default => null,
        };
    }

    /**
     * Applies the adopted generation to the authoritative store: I/S/L keep
     * their imported content, N/C/K are rebuilt from the services SQL export
     * (the SQL diff always wins) and every block state is refreshed.
     */
    public function finalize(): string
    {
        $this->em->wrapInTransaction(function (): void {
            foreach (UdbBlock::all() as $block) {
                if (in_array($block, self::SQL_OWNED, true)) {
                    // SQL-backed blocks are rebuilt from the services SQL
                    // export: the peer content is acknowledged, discarded
                    // and replaced by the SQL diff.
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

    /** @return array{block: UdbBlock, roundId: int, txid: string, digest: string}|null */
    private function begin(UdbFrame $frame, UdbBlock $block): ?array
    {
        assert(null !== $frame->roundId && null !== $frame->txid);

        if (in_array($block->letter(), $this->completed, true)) {
            // Retransmission of an already completed block is idempotent.
            return ['block' => $block, 'roundId' => $frame->roundId, 'txid' => $frame->txid, 'digest' => $frame->checksum ?? ''];
        }

        $this->stages[$block->letter()] = [
            'txid' => $frame->txid,
            'roundId' => $frame->roundId,
            'digest' => $frame->checksum ?? '',
            'entries' => [],
            'bytes' => 0,
        ];
        $this->logger->info('UDB wire bootstrap: staged transfer started.', [
            'block' => $block->letter(),
            'round' => $frame->roundId,
        ]);

        return null;
    }

    private function put(UdbFrame $frame, UdbBlock $block): null
    {
        $stage = $this->stageFor($frame, $block);
        if (null === $stage || null === $frame->path || null === $frame->value) {
            return null;
        }

        if ([] !== $stage['entries'] && count($stage['entries']) >= $this->maxStageRecords) {
            $this->abortStage($block, 'record limit exhausted');

            return null;
        }

        if (strlen($frame->path) + strlen($frame->value) > UdbPathCodec::RECORD_LINE_MAX || !UdbPathCodec::fitsLimits($frame->path, $frame->value)) {
            $this->abortStage($block, 'malformed or oversized PUT record');

            return null;
        }

        $components = [];
        foreach (explode('::', $frame->path) as $component) {
            // fitsLimits() proved every component decodes; this is infallible.
            $decoded = UdbPathCodec::decodeComponent($component);
            assert(null !== $decoded);
            $components[] = $decoded;
        }

        if (!UdbSchema::validate($block, $components, $frame->value)) {
            $this->abortStage($block, 'schema-invalid PUT record');

            return null;
        }

        $stage['entries'][$frame->path] = $frame->value;
        $stage['bytes'] += strlen($frame->path) + strlen($frame->value);
        $this->stages[$block->letter()] = $stage;
        if ($stage['bytes'] > $this->maxStageBytes) {
            $this->abortStage($block, 'byte limit exhausted');
        }

        return null;
    }

    /** @return array{block: UdbBlock, roundId: int, txid: string, digest: string}|null */
    private function end(UdbFrame $frame, UdbBlock $block): ?array
    {
        assert(null !== $frame->roundId && null !== $frame->txid);

        $stage = $this->stageFor($frame, $block);
        if (null === $stage) {
            return null;
        }

        unset($this->stages[$block->letter()]);

        $digest = UdbChecksum::fromRecords(self::tuples($stage['entries']));
        if (($frame->checksum ?? '') !== $digest) {
            $this->logger->warning('UDB wire bootstrap: staged digest mismatch; discarding the block.', [
                'block' => $block->letter(),
            ]);

            return null;
        }

        if (!in_array($block->letter(), $this->completed, true)) {
            $this->completed[] = $block->letter();
        }
        if (!in_array($block, self::SQL_OWNED, true)) {
            // Blocks Ares does not model keep the IRCd content; SQL-owned
            // blocks are validated and acknowledged, then discarded.
            $this->imported[$block->letter()] = $stage['entries'];
        }

        $this->logger->info('UDB wire bootstrap: staged transfer validated.', [
            'block' => $block->letter(),
            'records' => count($stage['entries']),
        ]);

        return ['block' => $block, 'roundId' => $frame->roundId, 'txid' => $frame->txid, 'digest' => $digest];
    }

    /**
     * Returns the open stage for the frame when block, round and txid all
     * match; otherwise null (late or unknown transaction frames are ignored).
     *
     * @return array{txid: string, roundId: int, digest: string, entries: array<string, string>, bytes: int}|null
     */
    private function stageFor(UdbFrame $frame, UdbBlock $block): ?array
    {
        $stage = $this->stages[$block->letter()] ?? null;
        if (null === $stage || $stage['txid'] !== $frame->txid || $stage['roundId'] !== $frame->roundId) {
            return null;
        }

        return $stage;
    }

    private function abortStage(UdbBlock $block, string $reason): void
    {
        unset($this->stages[$block->letter()]);
        $this->logger->warning('UDB wire bootstrap: staged transfer discarded.', [
            'block' => $block->letter(),
            'reason' => $reason,
        ]);
    }

    private function blockChecksum(UdbBlock $block): string
    {
        $tuples = [];
        foreach ($this->records->recordsByBlock($block->letter()) as $path => $value) {
            $tuples[] = [$path, $value];
        }

        return UdbChecksum::fromRecords($tuples);
    }

    private function fingerprint(): string
    {
        $lines = [];
        foreach (UdbBlock::all() as $block) {
            $state = $this->blockStates->all()[$block->letter()] ?? null;
            $checksum = null !== $state ? $state->getChecksum() : UdbChecksum::EMPTY;
            $lines[] = $block->letter() . ':' . $checksum;
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
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
}
