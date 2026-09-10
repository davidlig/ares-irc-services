<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
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

    public function upsert(string $block, string $path, string $value): bool
    {
        if ($this->fail) {
            throw new RuntimeException('store unavailable');
        }

        $identity = strtolower($path);
        foreach ($this->blocks[$block] ?? [] as $candidate => $existing) {
            if (strtolower($candidate) !== $identity) {
                continue;
            }

            if ($existing === $value) {
                return false;
            }

            $this->blocks[$block][$candidate] = $value;

            return true;
        }

        $this->blocks[$block][$path] = $value;

        return true;
    }

    public function deleteCascade(string $block, string $path): bool
    {
        if ($this->fail) {
            throw new RuntimeException('store unavailable');
        }

        $identity = strtolower($path);
        $prefix = $identity . '::';
        $deleted = false;
        foreach ($this->blocks[$block] ?? [] as $candidate => $value) {
            $candidateIdentity = strtolower($candidate);
            if ($candidateIdentity === $identity || str_starts_with($candidateIdentity, $prefix)) {
                unset($this->blocks[$block][$candidate]);
                $deleted = true;
            }
        }

        return $deleted;
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
