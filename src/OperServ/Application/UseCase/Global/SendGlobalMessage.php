<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Global;

use DateTimeImmutable;

/** Typed intention to send a network-wide NOTICE or PRIVMSG. */
final readonly class SendGlobalMessage
{
    public function __construct(
        public string $actorNickname,
        public string $senderMaskOrServiceNickname,
        public string $messageType,
        public string $message,
        public DateTimeImmutable $occurredAt,
    ) {}
}
