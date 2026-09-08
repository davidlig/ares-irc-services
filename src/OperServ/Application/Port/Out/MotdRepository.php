<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use DateTimeImmutable;

/** Persistence capabilities required by MOTD administration. */
interface MotdRepository
{
    /** @param 'NOTICE'|'PRIVMSG' $messageType */
    public function add(
        string $text,
        string $botNickname,
        string $messageType,
        ?int $creatorAccountId,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $expiresAt,
    ): MotdEntry;

    public function findById(int $id): ?MotdEntry;

    /** @return list<MotdEntry> */
    public function findAll(): array;

    /** @return list<MotdEntry> */
    public function findExpiredAt(DateTimeImmutable $at): array;

    public function remove(MotdEntry $entry): void;
}
