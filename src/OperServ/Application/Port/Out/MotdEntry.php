<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use DateTimeImmutable;

/** Persistent MOTD state exposed without leaking Doctrine entities. */
final readonly class MotdEntry
{
    /** @param 'NOTICE'|'PRIVMSG' $messageType */
    public function __construct(
        public int $id,
        public string $text,
        public string $botNickname,
        public string $messageType,
        public bool $enabled,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $expiresAt,
        public int $shownCount,
    ) {}

    public function isExpiredAt(DateTimeImmutable $at): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $at;
    }
}
