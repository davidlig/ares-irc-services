<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;

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
