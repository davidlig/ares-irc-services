<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use App\OperServ\Application\UseCase\Badmail\BadmailEntryView;
use App\OperServ\Domain\Entity\BadmailEntry;

interface BadmailRepository
{
    /** Atomically insert; return false only for an already-blocked domain. */
    public function add(BadmailEntry $entry): bool;

    public function delete(int $id): bool;

    public function clear(): int;

    public function contains(string $normalizedDomain): bool;

    public function countMatching(string $pattern): int;

    /** @return list<BadmailEntryView> Stable ascending IDs; only '*' is a wildcard. */
    public function page(string $pattern, int $offset, int $limit): array;
}
