<?php

declare(strict_types=1);

namespace App\Domain\Udb\Entity;

use DateTimeImmutable;

/**
 * One authoritative UDB 4 record of the services-owned store.
 *
 * Paths are stored in canonical percent-encoded wire form WITHOUT the block
 * prefix. The identity path is the ASCII-lowercased path: UDB compares record
 * keys case-insensitively (strcasecmp), so the lowercased identity is the
 * unique per-block key used for upserts and cascade deletes.
 */
class UdbRecord
{
    private int $id;

    private readonly string $identityPath;

    private DateTimeImmutable $updatedAt;

    public function __construct(
        private readonly string $block,
        private readonly string $path,
        private string $value,
    ) {
        $this->identityPath = self::identity($path);
        $this->updatedAt = new DateTimeImmutable();
    }

    /** ASCII-lowercased canonical path (UDB record lookup semantics). */
    public static function identity(string $path): string
    {
        return strtolower($path);
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
        $this->value = $value;
        $this->updatedAt = new DateTimeImmutable();
    }
}
