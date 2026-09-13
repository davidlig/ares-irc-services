<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Model;

use DateTimeImmutable;
use InvalidArgumentException;

use function preg_match;

/**
 * Per-block initialization marker of the authoritative UDB store.
 *
 * The mere existence of one row per UDB block (N, C, I, S, L, K) marks that
 * block as initialized: N/C/K were seeded from services SQL and I/S/L start
 * empty. The checksum, record count and modification time form the block's
 * persisted manifest.
 */
class UdbBlockState
{
    private int $id;

    private DateTimeImmutable $modifiedAt;

    public function __construct(
        private readonly string $block,
        private string $checksum,
        ?DateTimeImmutable $modifiedAt = null,
        private int $recordCount = 0,
    ) {
        self::assertChecksum($checksum);
        self::assertRecordCount($recordCount);
        $this->modifiedAt = $modifiedAt ?? new DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getBlock(): string
    {
        return $this->block;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getDigest(): string
    {
        return $this->checksum;
    }

    public function getRecordCount(): int
    {
        return $this->recordCount;
    }

    public function getModifiedAt(): DateTimeImmutable
    {
        return $this->modifiedAt;
    }

    public function update(
        string $checksum,
        int $recordCount,
        ?DateTimeImmutable $modifiedAt = null,
    ): void {
        self::assertChecksum($checksum);
        self::assertRecordCount($recordCount);

        $this->checksum = $checksum;
        $this->recordCount = $recordCount;
        $this->modifiedAt = $modifiedAt ?? new DateTimeImmutable();
    }

    private static function assertChecksum(string $checksum): void
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $checksum)) {
            throw new InvalidArgumentException('A UDB block checksum must be a lowercase SHA-256 digest.');
        }
    }

    private static function assertRecordCount(int $recordCount): void
    {
        if (0 > $recordCount) {
            throw new InvalidArgumentException('A UDB block record count cannot be negative.');
        }
    }
}
