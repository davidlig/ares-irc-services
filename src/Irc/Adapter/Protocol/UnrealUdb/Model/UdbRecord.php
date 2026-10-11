<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Model;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

use function count;
use function explode;
use function hash;
use function implode;
use function strtolower;
use function strtoupper;
use function substr;

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

    private int $valueKind;

    public function __construct(
        private readonly string $block,
        private readonly string $path,
        string $value,
        ?UdbValueKind $valueKind = null,
    ) {
        $this->identityPath = self::identity($path, $block);
        $this->identityHash = self::identityHash($this->identityPath);
        $kind = $valueKind ?? UdbSchema::typedValueForStoredRecord($block, $path, $value)?->kind;
        if (null === $kind) {
            throw new InvalidArgumentException('The UDB record path does not identify a canonical value type.');
        }
        self::assertValueMatchesKind($value, $kind);
        $this->valueKind = $kind->value;
        $this->value = UdbValueKind::Number === $kind ? UdbSchema::canonicalizeValue($value) : $value;
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

    public function getValueKind(): UdbValueKind
    {
        return UdbValueKind::from($this->valueKind);
    }

    public function getUdbValue(): UdbValue
    {
        return match ($this->getValueKind()) {
            UdbValueKind::None => UdbValue::none(),
            UdbValueKind::Number => UdbValue::number(UdbUnsignedDecimal::parse(substr($this->value, 1)) ?? throw new InvalidArgumentException('A persisted numeric UDB value is malformed.')),
            UdbValueKind::String => UdbValue::string($this->value),
        };
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function updateValue(string $value, ?UdbValueKind $valueKind = null): void
    {
        $kind = $valueKind ?? UdbSchema::typedValueForStoredRecord($this->block, $this->path, $value)?->kind;
        if (null === $kind) {
            throw new InvalidArgumentException('The UDB record path does not identify a canonical value type.');
        }
        self::assertValueMatchesKind($value, $kind);
        $this->valueKind = $kind->value;
        $this->value = UdbValueKind::Number === $kind ? UdbSchema::canonicalizeValue($value) : $value;
        $this->updatedAt = new DateTimeImmutable();
    }

    private static function assertValueMatchesKind(string $value, UdbValueKind $kind): void
    {
        if (UdbValueKind::None === $kind && '' !== $value) {
            throw new InvalidArgumentException('The UDB stored value does not match its persisted value kind.');
        }
        if (UdbValueKind::Number === $kind
            && null === UdbUnsignedDecimal::parse(substr(UdbSchema::canonicalizeValue($value), 1))
        ) {
            throw new InvalidArgumentException('A UDB numeric value must use its canonical decimal marker.');
        }
    }
}
