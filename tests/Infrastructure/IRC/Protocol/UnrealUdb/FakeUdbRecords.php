<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Domain\Udb\Repository\UdbRecordRepositoryInterface;
use RuntimeException;

/**
 * In-memory UdbRecordRepositoryInterface fake with optional failure injection.
 */
final class FakeUdbRecords implements UdbRecordRepositoryInterface
{
    /** @var array<string, array<string, string>> */
    public array $blocks = [];

    public bool $fail = false;

    public function recordsByBlock(string $block): array
    {
        return $this->blocks[$block] ?? [];
    }

    public function upsert(string $block, string $path, string $value): void
    {
        if ($this->fail) {
            throw new RuntimeException('store unavailable');
        }

        $this->blocks[$block][$path] = $value;
    }

    public function deleteCascade(string $block, string $path): void
    {
        if ($this->fail) {
            throw new RuntimeException('store unavailable');
        }

        $identity = strtolower($path);
        $prefix = $identity . '::';
        foreach ($this->blocks[$block] ?? [] as $candidate => $value) {
            $candidateIdentity = strtolower($candidate);
            if ($candidateIdentity === $identity || str_starts_with($candidateIdentity, $prefix)) {
                unset($this->blocks[$block][$candidate]);
            }
        }
    }

    public function seedBlock(string $block, array $records): void
    {
        if ($this->fail) {
            throw new RuntimeException('store unavailable');
        }

        foreach ($records as $path => $value) {
            $this->blocks[$block][$path] = $value;
        }
    }

    public function replaceBlock(string $block, array $records): void
    {
        if ($this->fail) {
            throw new RuntimeException('store unavailable');
        }

        $this->blocks[$block] = $records;
    }
}
