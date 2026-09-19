<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use App\OperServ\Application\Model\MessageDelivery;
use DateTimeImmutable;

/** Persistence capabilities required by MOTD administration. */
interface MotdRepository
{
    public function add(
        string $text,
        string $botNickname,
        MessageDelivery $delivery,
        ?int $creatorAccountId,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $expiresAt,
    ): MotdEntry;

    public function findById(int $id): ?MotdEntry;

    /** @return list<MotdEntry> */
    public function findAll(): array;

    /** @return list<MotdEntry> */
    public function findActiveAt(DateTimeImmutable $at): array;

    /** @return list<MotdEntry> */
    public function findExpiredAt(DateTimeImmutable $at): array;

    public function remove(MotdEntry $entry): void;

    public function recordShown(int $id): void;

    public function deleteByCreatorAccountId(int $accountId): void;
}
