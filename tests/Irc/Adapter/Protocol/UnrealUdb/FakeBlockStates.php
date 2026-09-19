<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use DateTimeImmutable;

/**
 * In-memory UdbBlockStateRepositoryInterface fake.
 */
final class FakeBlockStates implements UdbBlockStateRepositoryInterface
{
    /** @var array<string, UdbBlockState> */
    public array $states = [];

    public function all(): array
    {
        return $this->states;
    }

    public function upsert(
        string $block,
        string $checksum,
        int $recordCount,
        ?DateTimeImmutable $modifiedAt = null,
    ): void {
        $state = $this->states[$block] ?? new UdbBlockState($block, $checksum, $modifiedAt, $recordCount);
        $state->update($checksum, $recordCount, $modifiedAt);
        $this->states[$block] = $state;
    }
}
