<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Domain\Udb\Entity\UdbBlockState;
use App\Domain\Udb\Repository\UdbBlockStateRepositoryInterface;

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

    public function upsert(string $block, string $checksum): void
    {
        $state = $this->states[$block] ?? new UdbBlockState($block, $checksum);
        $state->update($checksum);
        $this->states[$block] = $state;
    }
}
