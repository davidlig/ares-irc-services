<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Takeover;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAuthorityState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbDatasetCheckpoint;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSnapshotValidator;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValueKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\SystemUdbClock;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbClock;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrameKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbValueEncoder;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use UnexpectedValueException;

use function array_unique;
use function array_values;
use function assert;
use function bin2hex;
use function count;
use function explode;
use function hash;
use function implode;
use function in_array;
use function is_bool;
use function is_int;
use function is_string;
use function random_bytes;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

use const PHP_INT_MAX;

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

    /** @var array<string, array{txid: string, roundId: UdbUnsignedDecimal, digest: string, watermark: UdbUnsignedDecimal, expectedCount: int, count: int, bytes: int, inactivityDeadline: int, absoluteDeadline: int}> */
    private array $stages = [];

    /** @var list<string> */
    private array $completed = [];

    /** @var array<string, array{txid: string, roundId: UdbUnsignedDecimal, digest: string, watermark: UdbUnsignedDecimal}> */
    private array $completedTransfers = [];

    /** @var array<string, true> */
    private array $requestedBlocks = [];

    private ?UdbUnsignedDecimal $roundId = null;

    private ?UdbUnsignedDecimal $roundWatermark = null;

    private ?UdbUnsignedDecimal $tailWatermark = null;

    private ?string $generationId = null;

    private ?string $rootSid = null;

    private ?string $rootEpoch = null;

    private bool $tailReplayComplete = false;

    /** @var array<string, array{digest: string, count: ?int}> */
    private array $inventory = [];

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

    /**
     * @return list<string>
     */
    public function completedBlocks(): array
    {
        return array_values(array_unique($this->completed));
    }

    public function isComplete(): bool
    {
        return count($this->completedBlocks()) >= count(UdbBlock::all());
    }

    public function roundId(): ?UdbUnsignedDecimal
    {
        return $this->roundId;
    }

    public function canPromote(): bool
    {
        return $this->isComplete() && $this->tailReplayComplete;
    }

    public function reset(): void
    {
        $this->discardGeneration();
        $this->stages = [];
        $this->completed = [];
        $this->completedTransfers = [];
        $this->requestedBlocks = [];
        $this->roundId = null;
        $this->roundWatermark = null;
        $this->tailWatermark = null;
        $this->generationId = null;
        $this->rootSid = null;
        $this->rootEpoch = null;
        $this->tailReplayComplete = false;
        $this->inventory = [];
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

    /** Stores an authorized post-inventory mutation without touching live service state. */
    public function acceptMutation(UdbFrame $frame): UdbWireTakeoverOutcome
    {
        if (null === $this->generationId || null === $this->rootSid || null === $this->rootEpoch
            || null === $this->tailWatermark || null === $frame->sequence || $this->tailReplayComplete
            || $frame->sourceSid !== $this->rootSid || $frame->epoch !== $this->rootEpoch
        ) {
            return UdbWireTakeoverOutcome::error($frame->block ?? UdbBlock::Nicks, $this->roundId ?? UdbUnsignedDecimal::fromInt(1), $frame->kind->value, 5);
        }

        $block = $frame->block;
        $path = $frame->path ?? '';
        $value = $frame->typedValue;
        if (in_array($frame->kind, [UdbFrameKind::Ins, UdbFrameKind::Del], true)) {
            $separator = strpos($path, '::');
            if (false === $separator) {
                return UdbWireTakeoverOutcome::error(UdbBlock::Nicks, $this->roundId ?? UdbUnsignedDecimal::fromInt(1), $frame->kind->value, 2);
            }
            $block = UdbBlock::fromLetter(strtoupper(substr($path, 0, $separator)));
            $path = substr($path, $separator + 2);
        }
        if (null === $block || !in_array($frame->kind, [UdbFrameKind::Ins, UdbFrameKind::Del, UdbFrameKind::Drp], true)
            || (UdbFrameKind::Ins === $frame->kind && (null === $value || !$this->validTypedPath($block, $path, $value)))
            || (UdbFrameKind::Del === $frame->kind && '' === $path)
        ) {
            return UdbWireTakeoverOutcome::error($block ?? UdbBlock::Nicks, $this->roundId ?? UdbUnsignedDecimal::fromInt(1), $frame->kind->value, 2);
        }

        $connection = $this->em->getConnection();
        $prior = $connection->fetchAssociative('SELECT block, operation, path, value, value_kind FROM udb_staged_mutations WHERE generation_id = ? AND sequence = ?', [$this->generationId, (string) $frame->sequence]);
        if (false !== $prior) {
            $same = $prior['block'] === $block->letter()
                && $prior['operation'] === $frame->kind->value
                && $prior['path'] === $path
                && (null === $prior['value'] ? null : $this->sqlBinary($prior['value'])) === (null !== $value ? $value->logicalValue() : null)
                && (null === $value ? null : self::sqlInt($prior['value_kind'])) === (null !== $value ? $value->kind->value : null);

            return $same ? UdbWireTakeoverOutcome::ignored() : UdbWireTakeoverOutcome::error($block, $this->roundId ?? UdbUnsignedDecimal::fromInt(1), $frame->kind->value, 5);
        }

        $expected = $this->tailWatermark->increment();
        if (null === $expected || !$expected->equals($frame->sequence)) {
            return UdbWireTakeoverOutcome::error($block, $this->roundId ?? UdbUnsignedDecimal::fromInt(1), $frame->kind->value, 5);
        }
        $count = self::sqlInt($connection->fetchOne('SELECT COUNT(*) FROM udb_staged_mutations WHERE generation_id = ?', [$this->generationId]));
        $bytes = self::sqlInt($connection->fetchOne('SELECT COALESCE(SUM(LENGTH(path) + COALESCE(LENGTH(value), 0)), 0) FROM udb_staged_mutations WHERE generation_id = ?', [$this->generationId]));
        if ($count >= self::MAX_STAGE_RECORDS || $bytes + strlen($path) + strlen($value?->logicalValue() ?? '') > self::MAX_STAGE_BYTES) {
            return UdbWireTakeoverOutcome::error($block, $this->roundId ?? UdbUnsignedDecimal::fromInt(1), $frame->kind->value, 2);
        }
        $this->stageInsert('udb_staged_mutations', [
            'generation_id' => $this->generationId,
            'sequence' => (string) $frame->sequence,
            'block' => $block->letter(),
            'operation' => $frame->kind->value,
            'path' => $path,
            'value' => null !== $value ? $value->logicalValue() : null,
            'value_kind' => null !== $value ? $value->kind->value : null,
        ]);
        $this->tailWatermark = $frame->sequence;

        return UdbWireTakeoverOutcome::ignored();
    }

    public function finalize(): string
    {
        if (!$this->isComplete() || !$this->tailReplayComplete || null === $this->generationId || null === $this->rootSid || null === $this->rootEpoch || null === $this->roundWatermark) {
            throw new LogicException('Cannot finalize an incomplete UDB wire takeover round.');
        }

        $checkpoint = new UdbDatasetCheckpoint(new UdbStreamOrigin($this->rootSid, $this->rootEpoch), $this->tailWatermark ?? $this->roundWatermark);
        $this->em->wrapInTransaction(function () use ($checkpoint): void {
            $this->validateGenerationMetadata();
            foreach (UdbBlock::all() as $block) {
                $this->validatePersistedStage($block);
            }
            $this->replayTail();
            foreach (UdbBlock::all() as $block) {
                if (in_array($block, self::SQL_OWNED, true)) {
                    $this->records->replaceBlock($block->letter(), $this->exporter->encodedBlockRecords($block));
                } else {
                    $this->replaceBlockFromStage($block);
                }
                $summary = $this->liveBlockSummary($block);
                $this->blockStates->upsert($block->letter(), $summary['digest'], $summary['count']);
            }
            $state = $this->em->find(UdbAuthorityState::class, 1);
            if (!$state instanceof UdbAuthorityState) {
                $state = new UdbAuthorityState();
                $this->em->persist($state);
            }
            $state->recordImportedCheckpoint($checkpoint);
            $this->stageUpdate('udb_staged_generations', ['promoted' => true], ['generation_id' => $this->generationId]);
            $this->em->getConnection()->delete('udb_staged_mutations', ['generation_id' => $this->generationId]);
            $this->em->getConnection()->delete('udb_staged_records', ['generation_id' => $this->generationId]);
            $this->em->getConnection()->delete('udb_staged_transfers', ['generation_id' => $this->generationId]);
            $this->em->getConnection()->delete('udb_staged_generations', ['generation_id' => $this->generationId]);
            $this->em->flush();
        });
        $this->em->clear();

        return $this->fingerprint();
    }

    private function inventory(UdbFrame $frame, UdbBlock $block): UdbWireTakeoverOutcome
    {
        assert(null !== $frame->roundId);
        if (null === $frame->checksum || null === $frame->count || null === $frame->watermark || null === $frame->rootSid || null === $frame->rootEpoch) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'INF', 2);
        }
        if (null === $this->roundId) {
            $now = $this->clock->now();
            $this->roundId = $frame->roundId;
            $this->roundInactivityDeadline = $now + self::INACTIVITY_TIMEOUT;
            $this->roundAbsoluteDeadline = $now + self::ABSOLUTE_TIMEOUT;
            $this->roundWatermark = $frame->watermark;
            $this->tailWatermark = $frame->watermark;
            $this->rootSid = $frame->rootSid;
            $this->rootEpoch = $frame->rootEpoch;
            $this->generationId = bin2hex(random_bytes(16));
            $this->clearStagedGenerations();
            $this->createGeneration($frame);
        } elseif (!$this->roundId->equals($frame->roundId)) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'INF', 5);
        } elseif (null === $this->roundWatermark || !$this->roundWatermark->equals($frame->watermark) || $this->rootSid !== $frame->rootSid || $this->rootEpoch !== $frame->rootEpoch) {
            $this->reset();

            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'INF', 3);
        }

        $letter = $block->letter();
        if (isset($this->requestedBlocks[$letter])) {
            return UdbWireTakeoverOutcome::ignored();
        }

        $this->requestedBlocks[$letter] = true;
        $this->inventory[$letter] = ['digest' => $frame->checksum, 'count' => $frame->count];
        $this->touchRound();

        $local = $this->liveBlockSummary($block);
        if ($local['count'] === $frame->count && $local['digest'] === $frame->checksum) {
            $this->stageReusableBlock($block, $frame->roundId, $frame->checksum, $frame->count);
            $this->completed[] = $letter;

            return UdbWireTakeoverOutcome::ignored();
        }

        return UdbWireTakeoverOutcome::request($block, $frame->roundId);
    }

    private function stageReusableBlock(UdbBlock $block, UdbUnsignedDecimal $roundId, string $digest, int $count): void
    {
        if (null === $this->generationId || null === $this->roundWatermark) {
            throw new LogicException('A reusable UDB block has no staged generation checkpoint.');
        }
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $lastId = 0;
            while (true) {
                $rows = $connection->fetchAllAssociative('SELECT id, path, identity_path, identity_hash, value, value_kind FROM udb_records WHERE block = ? AND id > ? ORDER BY id ASC LIMIT 256', [$block->letter(), $lastId]);
                if ([] === $rows) {
                    break;
                }
                foreach ($rows as $row) {
                    $lastId = self::sqlInt($row['id']);
                    $this->stageInsert('udb_staged_records', [
                        'generation_id' => $this->generationId,
                        'block' => $block->letter(),
                        'path' => self::sqlString($row['path']),
                        'identity_path' => $this->sqlBinary($row['identity_path']),
                        'identity_hash' => $this->sqlBinary($row['identity_hash']),
                        'value' => $this->sqlBinary($row['value']),
                        'value_kind' => self::sqlInt($row['value_kind']),
                    ]);
                }
            }
            $this->stageInsert('udb_staged_transfers', [
                'generation_id' => $this->generationId,
                'block' => $block->letter(),
                'round_id' => (string) $roundId,
                'txid' => 'REUSE000',
                'digest' => $digest,
                'watermark' => (string) $this->roundWatermark,
                'expected_count' => $count,
                'record_count' => $count,
                'byte_count' => 0,
                'complete' => true,
            ]);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    private function begin(UdbFrame $frame, UdbBlock $block): UdbWireTakeoverOutcome
    {
        assert(null !== $frame->roundId);
        if (null === $frame->txid || null === $frame->checksum) {
            return UdbWireTakeoverOutcome::ignored();
        }
        if (null === $this->roundId || !$this->roundId->equals($frame->roundId) || !isset($this->requestedBlocks[$block->letter()])) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'BEGIN', 5);
        }
        if (isset($this->completedTransfers[$block->letter()]) || isset($this->stages[$block->letter()])) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'BEGIN', 4);
        }
        $inventory = $this->inventory[$block->letter()] ?? null;
        $watermark = $frame->watermark;
        if (null === $inventory || null === $inventory['count'] || null === $watermark || $inventory['digest'] !== $frame->checksum || null === $this->roundWatermark || !$this->roundWatermark->equals($watermark)) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'BEGIN', 3);
        }
        if (null === $this->generationId) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'BEGIN', 5);
        }

        $now = $this->clock->now();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $connection->delete('udb_staged_records', ['generation_id' => $this->generationId, 'block' => $block->letter()]);
            $connection->delete('udb_staged_transfers', ['generation_id' => $this->generationId, 'block' => $block->letter()]);
            $this->stageInsert('udb_staged_transfers', [
                'generation_id' => $this->generationId,
                'block' => $block->letter(),
                'round_id' => (string) $frame->roundId,
                'txid' => $frame->txid,
                'digest' => $frame->checksum,
                'watermark' => (string) $watermark,
                'expected_count' => $inventory['count'],
                'record_count' => 0,
                'byte_count' => 0,
                'complete' => false,
            ]);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }
        $this->stages[$block->letter()] = [
            'txid' => $frame->txid,
            'roundId' => $frame->roundId,
            'digest' => $frame->checksum ?? '',
            'watermark' => $watermark,
            'expectedCount' => $inventory['count'],
            'count' => 0,
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
        if (null === $stage || null === $frame->path || null === $frame->typedValue || null === $frame->value || null === $this->generationId) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'PUT', 5);
        }
        if ($stage['count'] >= $this->maxStageRecords) {
            return $this->abortWithError($block, $frame->roundId, 'PUT', 'record limit exhausted');
        }
        $logicalValue = $frame->typedValue->logicalValue();
        if (strlen($logicalValue) > UdbPathCodec::VALUE_MAX) {
            return $this->abortWithError($block, $frame->roundId, 'PUT', 'oversized typed value');
        }
        $wireValue = UdbValueEncoder::encode($frame->typedValue);
        if (strlen($frame->path) + strlen($wireValue) > UdbPathCodec::RECORD_LINE_MAX || !UdbPathCodec::fitsLimits($frame->path, $logicalValue)) {
            return $this->abortWithError($block, $frame->roundId, 'PUT', 'malformed or oversized PUT record');
        }

        $components = [];
        foreach (explode('::', $frame->path) as $component) {
            $decoded = UdbPathCodec::decodeComponent($component);
            assert(null !== $decoded);
            $components[] = $decoded;
        }
        if (!UdbSchema::validateTyped($block, $components, $frame->typedValue)) {
            return $this->abortWithError($block, $frame->roundId, 'PUT', 'schema-invalid PUT record');
        }

        $identityPath = UdbRecord::identity($frame->path, $block->letter());
        $identityHash = UdbRecord::identityHash($identityPath);
        $rowBytes = strlen($frame->path) + strlen($wireValue);
        $connection = $this->em->getConnection();
        try {
            $this->stageInsert('udb_staged_records', [
                'generation_id' => $this->generationId,
                'block' => $block->letter(),
                'path' => $frame->path,
                'identity_path' => $identityPath,
                'identity_hash' => $identityHash,
                'value' => $frame->typedValue->logicalValue(),
                'value_kind' => $frame->typedValue->kind->value,
            ]);
        } catch (Throwable) {
            return $this->abortWithError($block, $frame->roundId, 'PUT', 'duplicate or unpersistable path');
        }
        ++$stage['count'];
        $stage['bytes'] += $rowBytes;
        $stage['inactivityDeadline'] = $this->clock->now() + self::INACTIVITY_TIMEOUT;
        $this->stages[$block->letter()] = $stage;
        if ($stage['bytes'] > $this->maxStageBytes) {
            $this->em->getConnection()->delete('udb_staged_records', ['generation_id' => $this->generationId, 'block' => $block->letter()]);

            return $this->abortWithError($block, $frame->roundId, 'PUT', 'byte limit exhausted');
        }
        $this->stageUpdate('udb_staged_transfers', ['record_count' => $stage['count'], 'byte_count' => $stage['bytes']], ['generation_id' => $this->generationId, 'block' => $block->letter()]);
        $this->touchRound();

        return UdbWireTakeoverOutcome::ignored();
    }

    private function end(UdbFrame $frame, UdbBlock $block): UdbWireTakeoverOutcome
    {
        assert(null !== $frame->roundId);
        if (null === $frame->txid) {
            return UdbWireTakeoverOutcome::ignored();
        }
        if (null === $frame->watermark) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'END', 2);
        }
        $watermark = $frame->watermark;
        $completed = $this->completedTransfers[$block->letter()] ?? null;
        if (null !== $completed) {
            if ($completed['roundId']->equals($frame->roundId) && $completed['txid'] === $frame->txid && $completed['digest'] === $frame->checksum && $completed['watermark']->equals($watermark)) {
                return UdbWireTakeoverOutcome::acknowledge($block, $frame->roundId, $frame->txid, $completed['digest'], $completed['watermark']);
            }

            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'END', 5);
        }

        $stage = $this->stageFor($frame, $block);
        if (null === $stage) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'END', 5);
        }
        unset($this->stages[$block->letter()]);

        if (null === $this->generationId) {
            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'END', 5);
        }
        $digest = UdbChecksum::fromRecords($block, $this->stagedRows($block));
        if (($frame->checksum ?? '') !== $digest
            || $stage['digest'] !== $digest
            || !$stage['watermark']->equals($watermark)
            || $stage['count'] !== $stage['expectedCount']
        ) {
            $this->em->getConnection()->delete('udb_staged_records', ['generation_id' => $this->generationId, 'block' => $block->letter()]);
            $this->em->getConnection()->delete('udb_staged_transfers', ['generation_id' => $this->generationId, 'block' => $block->letter()]);

            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'END', 3);
        }
        if (!new UdbSnapshotValidator()->validate($block, $this->stagedValidationRows($block))) {
            $this->em->getConnection()->delete('udb_staged_records', ['generation_id' => $this->generationId, 'block' => $block->letter()]);
            $this->em->getConnection()->delete('udb_staged_transfers', ['generation_id' => $this->generationId, 'block' => $block->letter()]);

            return UdbWireTakeoverOutcome::error($block, $frame->roundId, 'END', 2);
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->stageUpdate('udb_staged_transfers', ['complete' => true], ['generation_id' => $this->generationId, 'block' => $block->letter()]);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }

        $this->completed[] = $block->letter();
        $this->completedTransfers[$block->letter()] = ['roundId' => $frame->roundId, 'txid' => $frame->txid, 'digest' => $digest, 'watermark' => $watermark];
        $this->touchRound();

        return UdbWireTakeoverOutcome::acknowledge($block, $frame->roundId, $frame->txid, $digest, $watermark);
    }

    /**
     * @return array{txid: string, roundId: UdbUnsignedDecimal, digest: string, watermark: UdbUnsignedDecimal, expectedCount: int, count: int, bytes: int, inactivityDeadline: int, absoluteDeadline: int}|null
     */
    private function stageFor(UdbFrame $frame, UdbBlock $block): ?array
    {
        $stage = $this->stages[$block->letter()] ?? null;
        if (null === $stage || null === $frame->roundId || $stage['txid'] !== $frame->txid || !$stage['roundId']->equals($frame->roundId)) {
            return null;
        }
        $now = $this->clock->now();
        if ($now >= $stage['inactivityDeadline'] || $now >= $stage['absoluteDeadline']) {
            $this->reset();

            return null;
        }

        return $stage;
    }

    private function abortWithError(UdbBlock $block, UdbUnsignedDecimal $roundId, string $subcommand, string $reason): UdbWireTakeoverOutcome
    {
        unset($this->stages[$block->letter()]);
        if (null !== $this->generationId) {
            $this->em->getConnection()->delete('udb_staged_records', ['generation_id' => $this->generationId, 'block' => $block->letter()]);
            $this->em->getConnection()->delete('udb_staged_transfers', ['generation_id' => $this->generationId, 'block' => $block->letter()]);
        }
        $this->logger->warning('UDB wire bootstrap: staged transfer discarded.', ['block' => $block->letter(), 'reason' => $reason]);

        return UdbWireTakeoverOutcome::error($block, $roundId, $subcommand, 2);
    }

    public function markTailReplayComplete(): void
    {
        if (!$this->isComplete() || null === $this->generationId) {
            return;
        }

        foreach (UdbBlock::all() as $block) {
            $this->validatePersistedStage($block);
        }
        $this->tailReplayComplete = true;
    }

    /** Revalidate the received checkpoint before replay within the promotion transaction. */
    private function replayTail(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $lastId = 0;
            while (true) {
                $rows = $connection->fetchAllAssociative('SELECT id, block, operation, path, value, value_kind FROM udb_staged_mutations WHERE generation_id = ? AND id > ? ORDER BY id ASC LIMIT 256', [$this->generationId, $lastId]);
                if ([] === $rows) {
                    break;
                }
                foreach ($rows as $row) {
                    $lastId = self::sqlInt($row['id']);
                    $block = UdbBlock::fromLetter(self::sqlString($row['block']));
                    if (null === $block) {
                        throw new LogicException('A staged mutation has an invalid block.');
                    }
                    $this->applyTailMutation($block, self::sqlString($row['operation']), self::sqlString($row['path']), $row['value'], $row['value_kind']);
                }
            }
            foreach (UdbBlock::all() as $block) {
                if (!new UdbSnapshotValidator()->validate($block, $this->stagedValidationRows($block))) {
                    throw new LogicException('A replayed UDB block failed typed aggregate validation.');
                }
            }
            $connection->commit();
            $this->tailReplayComplete = true;
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    private function createGeneration(UdbFrame $frame): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->stageInsert('udb_staged_generations', [
                'generation_id' => $this->generationId,
                'round_id' => (string) $frame->roundId,
                'root_sid' => $this->rootSid,
                'root_epoch' => $this->rootEpoch,
                'watermark' => (string) $this->roundWatermark,
                'created_at' => $this->clock->now(),
                'promoted' => false,
            ]);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    private function discardGeneration(): void
    {
        if (null === $this->generationId) {
            return;
        }
        $connection = $this->em->getConnection();
        $connection->delete('udb_staged_records', ['generation_id' => $this->generationId]);
        $connection->delete('udb_staged_mutations', ['generation_id' => $this->generationId]);
        $connection->delete('udb_staged_transfers', ['generation_id' => $this->generationId]);
        $connection->delete('udb_staged_generations', ['generation_id' => $this->generationId, 'promoted' => false], ['promoted' => Types::BOOLEAN]);
    }

    private function clearStagedGenerations(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM udb_staged_records');
        $connection->executeStatement('DELETE FROM udb_staged_mutations');
        $connection->executeStatement('DELETE FROM udb_staged_transfers');
        $connection->executeStatement('DELETE FROM udb_staged_generations');
    }

    /**
     * @return iterable<array{0: string, 1: UdbValue}>
     */
    private function stagedRows(UdbBlock $block): iterable
    {
        foreach ($this->stagedDbRows($block) as $row) {
            yield [$row['path'], $this->valueFromRow($row)];
        }
    }

    /**
     * @return iterable<array{path: string, identityPath: string, value: string, value_kind: int}>
     */
    private function stagedDbRows(UdbBlock $block): iterable
    {
        if (null === $this->generationId) {
            return;
        }
        $lastId = 0;
        $connection = $this->em->getConnection();
        while (true) {
            $rows = $connection->fetchAllAssociative('SELECT id, path, identity_path AS identityPath, value, value_kind FROM udb_staged_records WHERE generation_id = ? AND block = ? AND id > ? ORDER BY id ASC LIMIT 256', [$this->generationId, $block->letter(), $lastId]);
            if ([] === $rows) {
                return;
            }
            foreach ($rows as $row) {
                $lastId = self::sqlInt($row['id']);
                yield [
                    'path' => self::sqlString($row['path']),
                    'identityPath' => $this->sqlBinary($row['identityPath']),
                    'value' => $this->sqlBinary($row['value']),
                    'value_kind' => self::sqlInt($row['value_kind']),
                ];
            }
        }
    }

    /**
     * @param array{value: string, value_kind: int} $row
     */
    private function valueFromRow(array $row): UdbValue
    {
        $kind = UdbValueKind::tryFrom($row['value_kind']) ?? throw new LogicException('A persisted staged record has an unknown typed value.');

        return match ($kind) {
            UdbValueKind::None => UdbValue::none(),
            UdbValueKind::String => UdbValue::string($row['value']),
            UdbValueKind::Number => UdbValue::number(UdbUnsignedDecimal::parse(substr($row['value'], 1)) ?? throw new LogicException('A persisted staged number is malformed.')),
        };
    }

    /**
     * @return iterable<array{path: string, value: UdbValue, identityPath: string}>
     */
    private function stagedValidationRows(UdbBlock $block): iterable
    {
        if (null === $this->generationId) {
            return;
        }
        $lastIdentityPath = null;
        $connection = $this->em->getConnection();
        while (true) {
            $sql = 'SELECT path, identity_path AS identityPath, value, value_kind FROM udb_staged_records WHERE generation_id = ? AND block = ?';
            $parameters = [$this->generationId, $block->letter()];
            if (null !== $lastIdentityPath) {
                $sql .= ' AND identity_path > ?';
                $parameters[] = $lastIdentityPath;
            }
            $types = [ParameterType::STRING, ParameterType::STRING];
            if (null !== $lastIdentityPath) {
                $types[] = Types::BINARY;
            }
            $rows = $connection->fetchAllAssociative($sql . ' ORDER BY identity_path ASC LIMIT 256', $parameters, $types);
            if ([] === $rows) {
                return;
            }
            foreach ($rows as $row) {
                $lastIdentityPath = $this->sqlBinary($row['identityPath']);
                yield ['path' => self::sqlString($row['path']), 'value' => $this->valueFromRow(['value' => $this->sqlBinary($row['value']), 'value_kind' => self::sqlInt($row['value_kind'])]), 'identityPath' => $lastIdentityPath];
            }
        }
    }

    private function replaceBlockFromStage(UdbBlock $block): void
    {
        $this->em->createQuery('DELETE FROM App\\Irc\\Adapter\\Protocol\\UnrealUdb\\Model\\UdbRecord r WHERE r.block = :block')->setParameter('block', $block->letter())->execute();
        $pending = 0;
        foreach ($this->stagedRows($block) as [$path, $value]) {
            $this->em->persist(new UdbRecord($block->letter(), $path, $value->logicalValue(), $value->kind));
            if (256 === ++$pending) {
                $this->em->flush();
                $this->em->clear();
                $pending = 0;
            }
        }
        if (0 !== $pending) {
            $this->em->flush();
            $this->em->clear();
        }
    }

    private function validTypedPath(UdbBlock $block, string $path, UdbValue $value): bool
    {
        $components = [];
        foreach (explode('::', $path) as $component) {
            $decoded = UdbPathCodec::decodeComponent($component);
            if (null === $decoded) {
                return false;
            }
            $components[] = $decoded;
        }

        return UdbPathCodec::fitsLimits($path, $value->logicalValue()) && UdbSchema::validateTyped($block, $components, $value);
    }

    private function applyTailMutation(UdbBlock $block, string $operation, string $path, mixed $logicalValue, mixed $valueKind): void
    {
        if (null === $this->generationId) {
            throw new LogicException('Cannot replay mutations without a staged generation.');
        }
        $connection = $this->em->getConnection();
        if ('DRP' === $operation) {
            $connection->delete('udb_staged_records', ['generation_id' => $this->generationId, 'block' => $block->letter()]);

            return;
        }

        if ('INS' === $operation) {
            $kind = UdbValueKind::tryFrom(self::sqlInt($valueKind)) ?? throw new LogicException('A staged INS has an invalid value kind.');
            $value = match ($kind) {
                UdbValueKind::None => UdbValue::none(),
                UdbValueKind::String => UdbValue::string($this->sqlBinary($logicalValue)),
                UdbValueKind::Number => UdbValue::number(UdbUnsignedDecimal::parse(substr($this->sqlBinary($logicalValue), 1)) ?? throw new LogicException('A staged INS has a malformed number.')),
            };
            if (!$this->validTypedPath($block, $path, $value)) {
                throw new LogicException('A staged INS is not valid for its typed schema.');
            }
            $identityPath = UdbRecord::identity($path, $block->letter());
            $identityHash = UdbRecord::identityHash($identityPath);
            $existing = $connection->fetchAssociative('SELECT id, identity_path FROM udb_staged_records WHERE generation_id = ? AND block = ? AND identity_hash = ?', [$this->generationId, $block->letter(), $identityHash], [ParameterType::STRING, ParameterType::STRING, Types::BINARY]);
            if (false !== $existing && $identityPath !== $this->sqlBinary($existing['identity_path'])) {
                throw new LogicException('A staged INS has a conflicting binary identity.');
            }
            $this->removeStagedNickForbidSiblings($block, $identityPath);
            if (false === $existing) {
                $count = self::sqlInt($connection->fetchOne('SELECT COUNT(*) FROM udb_staged_records WHERE generation_id = ?', [$this->generationId]));
                if ($count >= self::MAX_STAGE_RECORDS) {
                    throw new LogicException('The replayed generation exceeds the staged record limit.');
                }
                $this->stageInsert('udb_staged_records', [
                    'generation_id' => $this->generationId,
                    'block' => $block->letter(),
                    'path' => $path,
                    'identity_path' => $identityPath,
                    'identity_hash' => $identityHash,
                    'value' => $value->logicalValue(),
                    'value_kind' => $value->kind->value,
                ]);
            } else {
                $this->stageUpdate('udb_staged_records', [
                    'path' => $path,
                    'identity_path' => $identityPath,
                    'value' => $value->logicalValue(),
                    'value_kind' => $value->kind->value,
                ], ['id' => self::sqlInt($existing['id'])]);
            }

            return;
        }

        if ('DEL' !== $operation) {
            throw new LogicException('A staged mutation has an unsupported operation.');
        }
        $identity = UdbRecord::identity($path, $block->letter());
        $prefix = $identity . '::';
        $lastId = 0;
        while (true) {
            $rows = $connection->fetchAllAssociative('SELECT id, identity_path FROM udb_staged_records WHERE generation_id = ? AND block = ? AND id > ? ORDER BY id ASC LIMIT 256', [$this->generationId, $block->letter(), $lastId]);
            if ([] === $rows) {
                return;
            }
            foreach ($rows as $row) {
                $lastId = self::sqlInt($row['id']);
                $candidate = $this->sqlBinary($row['identity_path']);
                if ($identity === $candidate || str_starts_with($candidate, $prefix)) {
                    $connection->delete('udb_staged_records', ['id' => $lastId]);
                }
            }
        }
    }

    /** Replay runs inside the generation transaction; only this nick is replaced. */
    private function removeStagedNickForbidSiblings(UdbBlock $block, string $identity): void
    {
        $components = explode('::', $identity);
        if (UdbBlock::Nicks !== $block || 2 !== count($components) || 'forbid' !== $components[1]) {
            return;
        }
        $connection = $this->em->getConnection();
        $prefix = $components[0] . '::';
        $lastId = 0;
        while (true) {
            $rows = $connection->fetchAllAssociative('SELECT id, identity_path FROM udb_staged_records WHERE generation_id = ? AND block = ? AND id > ? ORDER BY id ASC LIMIT 256', [$this->generationId, $block->letter(), $lastId]);
            if ([] === $rows) {
                return;
            }
            foreach ($rows as $row) {
                $lastId = self::sqlInt($row['id']);
                $candidate = $this->sqlBinary($row['identity_path']);
                if ($identity !== $candidate && str_starts_with($candidate, $prefix)) {
                    $connection->delete('udb_staged_records', ['id' => $lastId]);
                }
            }
        }
    }

    private function validatePersistedStage(UdbBlock $block, bool $validateReceivedDigest = true): void
    {
        if (null === $this->generationId || !isset($this->inventory[$block->letter()])) {
            throw new LogicException('A complete imported generation is missing one block inventory.');
        }
        $transfer = $this->em->getConnection()->fetchAssociative(
            'SELECT digest, expected_count, record_count, watermark, complete, round_id FROM udb_staged_transfers WHERE generation_id = ? AND block = ?',
            [$this->generationId, $block->letter()],
        );
        $expected = $this->inventory[$block->letter()];
        if (false === $transfer
            || !$this->sqlBoolean($transfer['complete'])
            || $transfer['round_id'] !== (string) $this->roundId
            || $transfer['watermark'] !== (string) $this->roundWatermark
            || $transfer['digest'] !== $expected['digest']
            || self::sqlInt($transfer['expected_count']) !== $expected['count']
            || self::sqlInt($transfer['record_count']) !== $expected['count']
        ) {
            throw new LogicException('A persisted UDB block stage does not match its inventory checkpoint.');
        }
        $count = self::sqlInt($this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM udb_staged_records WHERE generation_id = ? AND block = ?', [$this->generationId, $block->letter()]));
        if (($validateReceivedDigest && ($count !== $expected['count'] || UdbChecksum::fromRecords($block, $this->stagedRows($block)) !== $expected['digest']))
            || !new UdbSnapshotValidator()->validate($block, $this->stagedValidationRows($block))) {
            throw new LogicException('A persisted UDB block stage failed final validation.');
        }
    }

    private function validateGenerationMetadata(): void
    {
        if (null === $this->generationId || null === $this->roundId || null === $this->roundWatermark || null === $this->rootSid || null === $this->rootEpoch) {
            throw new LogicException('The imported UDB generation has no complete root checkpoint.');
        }
        $row = $this->em->getConnection()->fetchAssociative('SELECT round_id, root_sid, root_epoch, watermark, promoted FROM udb_staged_generations WHERE generation_id = ?', [$this->generationId]);
        if (false === $row
            || $this->sqlBoolean($row['promoted'])
            || $row['round_id'] !== (string) $this->roundId
            || $row['root_sid'] !== $this->rootSid
            || $row['root_epoch'] !== $this->rootEpoch
            || $row['watermark'] !== (string) $this->roundWatermark
        ) {
            throw new LogicException('The staged UDB generation root checkpoint changed before promotion.');
        }
    }

    /**
     * @return array{digest: string, count: int}
     */
    private function liveBlockSummary(UdbBlock $block): array
    {
        $connection = $this->em->getConnection();
        $lastId = 0;
        $count = 0;
        $rows = (function () use ($connection, $block, &$lastId, &$count): Generator {
            while (true) {
                $batch = $connection->fetchAllAssociative('SELECT id, path, value, value_kind FROM udb_records WHERE block = ? AND id > ? ORDER BY id ASC LIMIT 256', [$block->letter(), $lastId]);
                if ([] === $batch) {
                    return;
                }
                foreach ($batch as $row) {
                    $lastId = self::sqlInt($row['id']);
                    ++$count;
                    $kind = UdbValueKind::tryFrom(self::sqlInt($row['value_kind'])) ?? throw new LogicException('A persisted live row has an unknown value kind.');
                    $value = match ($kind) {
                        UdbValueKind::None => UdbValue::none(),
                        UdbValueKind::String => UdbValue::string($this->sqlBinary($row['value'])),
                        UdbValueKind::Number => UdbValue::number(UdbUnsignedDecimal::parse(substr($this->sqlBinary($row['value']), 1)) ?? throw new LogicException('A persisted live numeric row is malformed.')),
                    };
                    yield [self::sqlString($row['path']), $value];
                }
            }
        })();

        return ['digest' => UdbChecksum::fromRecords($block, $rows), 'count' => $count];
    }

    private function touchRound(): void
    {
        if (null !== $this->roundId) {
            $this->roundInactivityDeadline = $this->clock->now() + self::INACTIVITY_TIMEOUT;
        }
    }

    private function fingerprint(): string
    {
        $lines = [];
        foreach (UdbBlock::all() as $block) {
            $state = $this->blockStates->all()[$block->letter()] ?? null;
            $lines[] = $block->letter() . ':' . (null !== $state ? $state->getChecksum() : UdbChecksum::empty($block));
        }

        return hash('sha256', implode("\n", $lines));
    }

    /** @param array<string, mixed> $data */
    private function stageInsert(string $table, array $data): void
    {
        $this->em->getConnection()->insert($table, $data, self::stagingTypes($data));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $criteria
     */
    private function stageUpdate(string $table, array $data, array $criteria): void
    {
        $this->em->getConnection()->update($table, $data, $criteria, self::stagingTypes($data + $criteria));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string|ParameterType>
     */
    private static function stagingTypes(array $data): array
    {
        $types = [];
        foreach ($data as $column => $value) {
            $types[$column] = match (true) {
                null === $value => ParameterType::NULL,
                'identity_path' === $column || 'identity_hash' === $column || 'value' === $column => Types::BINARY,
                'complete' === $column || 'promoted' === $column => Types::BOOLEAN,
                is_int($value) => Types::INTEGER,
                default => Types::STRING,
            };
        }

        return $types;
    }

    private function sqlBinary(mixed $value): string
    {
        $decoded = Type::getType(Types::BINARY)->convertToPHPValue($value, $this->em->getConnection()->getDatabasePlatform());
        if (!is_string($decoded)) {
            throw new UnexpectedValueException('A persisted UDB binary identity is invalid.');
        }

        return $decoded;
    }

    private function sqlBoolean(mixed $value): bool
    {
        // Raw DBAL fetches are not ORM type conversions. In particular (bool)'f'
        // is true in PHP, while it represents false in PostgreSQL.
        $normalized = match ($value) {
            true, 1, '1', 't', 'true' => true,
            false, 0, '0', 'f', 'false' => false,
            default => null,
        };
        $decoded = null === $normalized ? null : Type::getType(Types::BOOLEAN)->convertToPHPValue($normalized, $this->em->getConnection()->getDatabasePlatform());
        if (!is_bool($decoded)) {
            throw new UnexpectedValueException(null === $normalized ? 'A persisted UDB Boolean field is invalid.' : 'A persisted UDB Boolean field could not be decoded.');
        }

        return $decoded;
    }

    private static function sqlString(mixed $value): string
    {
        if (!is_string($value)) {
            throw new UnexpectedValueException('A persisted UDB text field is invalid.');
        }

        return $value;
    }

    private static function sqlInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            throw new UnexpectedValueException('A persisted UDB integer field is invalid.');
        }

        return (int) $value;
    }
}
