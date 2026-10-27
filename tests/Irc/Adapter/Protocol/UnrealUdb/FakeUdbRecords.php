<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbDatasetCheckpoint;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordMutationStoreInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use RuntimeException;

use function array_map;
use function str_starts_with;
use function strtolower;

/**
 * In-memory UdbRecordRepositoryInterface fake with optional failure injection.
 */
final class FakeUdbRecords implements UdbRecordRepositoryInterface, UdbRecordMutationStoreInterface
{
    /** @var array<string, array<string, string>> */
    public array $blocks = [];

    public bool $fail = false;

    private ?UdbStreamOrigin $origin = null;

    private UdbUnsignedDecimal $watermark;

    public function __construct()
    {
        $this->watermark = UdbUnsignedDecimal::fromInt(0);
    }

    public function recordsByBlock(string $block): array
    {
        $typed = [];
        foreach ($this->blocks[$block] ?? [] as $path => $value) {
            $typed[$path] = UdbSchema::typedValueForStoredRecord($block, $path, $value)
                ?? throw new RuntimeException('Fake UDB row is not schema-valid.');
        }

        return $typed;
    }

    public function upsert(string $block, string $path, UdbValue $value): bool
    {
        if ($this->fail) {
            throw new RuntimeException('store unavailable');
        }

        $identity = strtolower($path);
        foreach ($this->blocks[$block] ?? [] as $candidate => $existing) {
            if (strtolower($candidate) !== $identity) {
                continue;
            }

            if ($existing === $value->logicalValue()) {
                return false;
            }

            $this->blocks[$block][$candidate] = $value->logicalValue();

            return true;
        }

        $this->blocks[$block][$path] = $value->logicalValue();

        return true;
    }

    public function beginLocalStream(UdbStreamOrigin $origin): UdbDatasetCheckpoint
    {
        $this->origin = $origin;
        $this->watermark = UdbUnsignedDecimal::fromInt(0);

        return new UdbDatasetCheckpoint($origin, $this->watermark);
    }

    public function upsertWithManifest(string $block, string $path, UdbValue $value, UdbStreamOrigin $origin): ?UdbUnsignedDecimal
    {
        if (!$this->upsert($block, $path, $value)) {
            return null;
        }

        return $this->advance($origin);
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

    public function deleteCascadeWithManifest(string $block, string $path, UdbStreamOrigin $origin): ?UdbUnsignedDecimal
    {
        if (!$this->deleteCascade($block, $path)) {
            return null;
        }

        return $this->advance($origin);
    }

    public function expireLineWithManifest(string $path, int $expectedExpires, int $now, UdbStreamOrigin $origin): ?UdbUnsignedDecimal
    {
        if ($expectedExpires > $now) {
            return null;
        }

        $expiresPath = $path . '::expires';
        $expiresIdentity = strtolower($expiresPath);
        foreach ($this->blocks['K'] ?? [] as $candidate => $value) {
            if (strtolower($candidate) !== $expiresIdentity || '*' . $expectedExpires !== $value) {
                continue;
            }

            return $this->deleteCascade('K', $path) ? $this->advance($origin) : null;
        }

        return null;
    }

    public function allocateBlockDropSequence(string $block, UdbStreamOrigin $origin): UdbUnsignedDecimal
    {
        if ($this->fail) {
            throw new RuntimeException('store unavailable');
        }

        return $this->advance($origin);
    }

    public function seedBlock(string $block, array $records): void
    {
        if ($this->fail) {
            throw new RuntimeException('store unavailable');
        }

        foreach ($records as $path => $value) {
            $this->blocks[$block][$path] = $value->logicalValue();
        }
    }

    public function replaceBlock(string $block, array $records): void
    {
        if ($this->fail) {
            throw new RuntimeException('store unavailable');
        }

        $this->blocks[$block] = array_map(static fn (string|UdbValue $value): string => $value->logicalValue(), $records);
    }

    private function advance(UdbStreamOrigin $origin): UdbUnsignedDecimal
    {
        if (null === $this->origin || $this->origin->sid !== $origin->sid || $this->origin->epoch !== $origin->epoch) {
            throw new RuntimeException('Unexpected stream origin in fake UDB store.');
        }
        $this->watermark = $this->watermark->increment() ?? throw new RuntimeException('Fake UDB sequence overflow.');

        return $this->watermark;
    }
}
