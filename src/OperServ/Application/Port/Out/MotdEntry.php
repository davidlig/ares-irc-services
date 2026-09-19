<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use App\OperServ\Application\Model\MessageDelivery;
use DateTimeImmutable;

/** Persistent MOTD state exposed without leaking Doctrine entities. */
final readonly class MotdEntry
{
    public function __construct(
        public int $id,
        public string $text,
        public string $botNickname,
        public MessageDelivery $delivery,
        public bool $enabled,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $expiresAt,
        public int $shownCount,
        public ?int $creatorAccountId = null,
    ) {}

    public function isExpiredAt(DateTimeImmutable $at): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $at;
    }
}
