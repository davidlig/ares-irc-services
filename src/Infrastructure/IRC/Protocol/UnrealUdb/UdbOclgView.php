<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbFrame;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbOclgViewDigest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function count;

/**
 * Consumer state for the UDB 4 OCLG global operclass projection.
 *
 * Owns ONLY the availability view: atomic stage building from OCLG
 * BEGIN/ITEM/END frames, fail-closed validation (exact count + upstream
 * SHA-256 view digest) and withdrawal semantics. Direct-peer filtering and
 * wire routing stay in UdbSessionCoordinator; this class holds no session
 * or connection state.
 */
final class UdbOclgView
{
    /** @var array<string, string> Global operclass name => effective SHA-256 digest. */
    private array $available = [];

    /** @var array{epoch: string, generation: int, ready: bool, count: int, digest: string, entries: array<string, string>}|null */
    private ?array $stage = null;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** True only for classes in the last complete READY OCLG projection. */
    public function isOperclassGloballyAvailable(string $operclass): bool
    {
        return isset($this->available[$operclass]);
    }

    public function begin(UdbFrame $frame): void
    {
        if (null === $frame->epoch || null === $frame->roundId || null === $frame->status || null === $frame->count || null === $frame->checksum) {
            return;
        }

        $ready = 'READY' === $frame->status;
        if ((!$ready && ('INCOMPLETE' !== $frame->status || 0 !== $frame->count)) || 0 > $frame->count) {
            $this->discard('invalid OCLG BEGIN descriptor');

            return;
        }

        // A replacement begins by withdrawing the prior projection. Consumers
        // can never observe stale roles while an inbound view is incomplete.
        $this->available = [];
        $this->stage = [
            'epoch' => $frame->epoch,
            'generation' => $frame->roundId,
            'ready' => $ready,
            'count' => $frame->count,
            'digest' => $frame->checksum,
            'entries' => [],
        ];
    }

    public function item(UdbFrame $frame): void
    {
        if (null === $frame->epoch || null === $frame->roundId || null === $frame->path || null === $frame->checksum || null === $this->stage) {
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
        if (null === $this->stage || !$this->matchesStage($frame)) {
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
    }

    /** Drops the volatile projection (connection loss / new session). */
    public function reset(): void
    {
        $this->stage = null;
        $this->available = [];
    }

    private function matchesStage(UdbFrame $frame): bool
    {
        return null !== $frame->epoch
            && null !== $frame->roundId
            && $frame->epoch === $this->stage['epoch']
            && $frame->roundId === $this->stage['generation'];
    }

    private function discard(string $reason): void
    {
        $this->stage = null;
        $this->available = [];
        $this->logger->warning('Discarded OCLG snapshot: ' . $reason);
    }
}
