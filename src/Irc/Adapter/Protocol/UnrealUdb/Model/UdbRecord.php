<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Model;

use DateTimeImmutable;

use function count;
use function explode;
use function hash;
use function implode;
use function strtolower;
use function strtoupper;

/**
 * One authoritative UDB 4 record of the services-owned store.
 *
 * Paths are stored in canonical percent-encoded wire form WITHOUT the block
 * prefix. UDB compares keys case-insensitively except for the pattern component
 * of K::F profiles, whose decoded bytes are an exact, case-sensitive identity.
 */
class UdbRecord
{
    private int $id;

    private readonly string $identityPath;

    private readonly string $identityHash;

    private DateTimeImmutable $updatedAt;

    private string $value;

    public function __construct(
        private readonly string $block,
        private readonly string $path,
        string $value,
    ) {
        $this->identityPath = self::identity($path, $block);
        $this->identityHash = self::identityHash($this->identityPath);
        $this->value = UdbSchema::canonicalizeValue($value);
        $this->updatedAt = new DateTimeImmutable();
    }

    /** Canonical path identity matching udb_record_key_equal(). */
    public static function identity(string $path, ?string $block = null): string
    {
        if ('K' !== strtoupper($block ?? '')) {
            return strtolower($path);
        }

        $components = explode('::', $path);
        if (count($components) < 2 || 'F' !== strtoupper($components[0])) {
            return strtolower($path);
        }

        $components[0] = 'f';
        for ($index = 2; $index < count($components); ++$index) {
            $components[$index] = strtolower($components[$index]);
        }

        return implode('::', $components);
    }

    /**
     * Fixed-size binary identity used for the unique key.
     *
     * Indexing the full identity path is not portable: InnoDB rejects keys
     * longer than 3072 bytes and PostgreSQL btree entries are limited to
     * 2704 bytes. The digest is a collision-resistant fixed-size key for
     * the canonical identity instead.
     */
    public static function identityHash(string $identityPath): string
    {
        return hash('sha256', $identityPath, true);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getBlock(): string
    {
        return $this->block;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getIdentityPath(): string
    {
        return $this->identityPath;
    }

    public function getIdentityHash(): string
    {
        return $this->identityHash;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function updateValue(string $value): void
    {
        $this->value = UdbSchema::canonicalizeValue($value);
        $this->updatedAt = new DateTimeImmutable();
    }
}
