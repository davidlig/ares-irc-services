<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageMotd;

use DateTimeImmutable;

final readonly class MotdListEntry
{
    /** @param 'NOTICE'|'PRIVMSG' $messageType */
    public function __construct(
        public int $id,
        public string $text,
        public string $botNickname,
        public string $messageType,
        public bool $enabled,
        public bool $expired,
        public ?DateTimeImmutable $expiresAt,
        public int $shownCount,
    ) {}
}
