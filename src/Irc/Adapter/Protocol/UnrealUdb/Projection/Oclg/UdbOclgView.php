<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Projection\Oclg;

use App\Irc\Adapter\Protocol\UnrealUdb\Session\SystemUdbClock;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbClock;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbOclgViewDigest;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_keys;
use function count;
use function sort;

/** Atomic, epoch-correlated OCLG view with monotonic generations and a bounded stage. */
final class UdbOclgView
{
    private const int STAGE_TIMEOUT = 30;

    private const int MAX_CLASSES = 1024;

    /** @var array<string, string> */
    private array $available = [];

    /** @var array{epoch: string, generation: UdbUnsignedDecimal, ready: bool, count: int, digest: string, entries: array<string, string>, deadline: int}|null */
    private ?array $stage = null;

    /** @var array{epoch: string, generation: UdbUnsignedDecimal, ready: bool, count: int, digest: string}|null */
    private ?array $highWaterDescriptor = null;

    private ?string $expectedEpoch = null;

    private UdbUnsignedDecimal $generationHighWater;

    private ?UdbUnsignedDecimal $committedGeneration = null;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly UdbClock $clock = new SystemUdbClock(),
    ) {
        $this->generationHighWater = UdbUnsignedDecimal::fromInt(0);
    }

    public function expectEpoch(string $epoch): void
    {
        if ($this->expectedEpoch === $epoch) {
            return;
        }

        $this->available = [];
        $this->stage = null;
        $this->highWaterDescriptor = null;
        $this->generationHighWater = UdbUnsignedDecimal::fromInt(0);
        $this->committedGeneration = null;
        $this->expectedEpoch = $epoch;
    }

    public function isOperclassGloballyAvailable(string $operclass): bool
    {
        return isset($this->available[$operclass]);
    }

    /** @return list<string> */
    public function getAvailableOperclasses(): array
    {
        $keys = array_keys($this->available);
        sort($keys);

        return $keys;
    }

    public function begin(UdbFrame $frame): void
    {
        if (null === $frame->epoch || $frame->epoch !== $this->expectedEpoch || null === $frame->roundId || null === $frame->status || null === $frame->count || null === $frame->checksum) {
            return;
        }
        $ready = 'READY' === $frame->status;
        if ((!$ready && ('INCOMPLETE' !== $frame->status || 0 !== $frame->count)) || 0 > $frame->count || self::MAX_CLASSES < $frame->count) {
            $this->logger->warning('Ignored invalid OCLG BEGIN descriptor.');

            return;
        }

        $descriptor = [
            'epoch' => $frame->epoch,
            'generation' => $frame->roundId,
            'ready' => $ready,
            'count' => $frame->count,
            'digest' => $frame->checksum,
        ];
        if (0 > $frame->roundId->compare($this->generationHighWater)) {
            return;
        }
        if ($frame->roundId->equals($this->generationHighWater) && null !== $this->highWaterDescriptor) {
            if (!self::sameDescriptor($descriptor, $this->highWaterDescriptor)) {
                $this->logger->warning('Ignored conflicting OCLG high-water descriptor.');

                return;
            }
            if (null !== $this->stage || $this->committedGeneration?->equals($frame->roundId)) {
                return;
            }
        } else {
            $this->generationHighWater = $frame->roundId;
            $this->highWaterDescriptor = $descriptor;
        }

        $this->available = [];
        $this->stage = [
            'epoch' => $frame->epoch,
            'generation' => $frame->roundId,
            'ready' => $ready,
            'count' => $frame->count,
            'digest' => $frame->checksum,
            'entries' => [],
            'deadline' => $this->clock->now() + self::STAGE_TIMEOUT,
        ];
    }

    public function item(UdbFrame $frame): void
    {
        if ($this->expire() || null === $frame->epoch || null === $frame->roundId || null === $frame->path || null === $frame->checksum || null === $this->stage) {
            return;
        }
        if (!$this->matchesStage($frame) || !$this->stage['ready'] || isset($this->stage['entries'][$frame->path]) || $this->stage['count'] <= count($this->stage['entries'])) {
            $this->discard('invalid OCLG ITEM');

            return;
        }

        $this->stage['entries'][$frame->path] = $frame->checksum;
    }

    public function end(UdbFrame $frame): void
    {
        if ($this->expire() || null === $this->stage || !$this->matchesStage($frame)) {
            return;
        }

        $stage = $this->stage;
        $this->stage = null;
        if ($stage['count'] !== count($stage['entries']) || $stage['digest'] !== UdbOclgViewDigest::fromEntries($stage['ready'], $stage['entries'])) {
            $this->available = [];
            $this->logger->warning('Discarded invalid OCLG snapshot.');

            return;
        }

        $this->available = $stage['ready'] ? $stage['entries'] : [];
        $this->committedGeneration = $stage['generation'];
    }

    public function expire(): bool
    {
        if (null === $this->stage || $this->clock->now() < $this->stage['deadline']) {
            return false;
        }

        $this->discard('stage timeout');

        return true;
    }

    public function nextDeadline(): ?int
    {
        return $this->stage['deadline'] ?? null;
    }

    public function reset(): void
    {
        $this->stage = null;
        $this->available = [];
        $this->highWaterDescriptor = null;
        $this->expectedEpoch = null;
        $this->generationHighWater = UdbUnsignedDecimal::fromInt(0);
        $this->committedGeneration = null;
    }

    private function matchesStage(UdbFrame $frame): bool
    {
        return null !== $this->stage
            && null !== $frame->epoch
            && null !== $frame->roundId
            && $frame->epoch === $this->expectedEpoch
            && $frame->epoch === $this->stage['epoch']
            && $frame->roundId->equals($this->stage['generation']);
    }

    private function discard(string $reason): void
    {
        $this->stage = null;
        $this->available = [];
        $this->logger->warning('Discarded OCLG snapshot: ' . $reason);
    }

    /**
     * @param array{epoch: string, generation: UdbUnsignedDecimal, ready: bool, count: int, digest: string} $left
     * @param array{epoch: string, generation: UdbUnsignedDecimal, ready: bool, count: int, digest: string} $right
     */
    private static function sameDescriptor(array $left, array $right): bool
    {
        return $left['epoch'] === $right['epoch']
            && $left['generation']->equals($right['generation'])
            && $left['ready'] === $right['ready']
            && $left['count'] === $right['count']
            && $left['digest'] === $right['digest'];
    }
}
