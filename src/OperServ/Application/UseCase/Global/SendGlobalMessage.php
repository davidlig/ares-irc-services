<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Global;

use App\OperServ\Application\Model\MessageDelivery;
use DateTimeImmutable;

/** Typed intention to send a network-wide message. */
final readonly class SendGlobalMessage
{
    public function __construct(
        public string $actorNickname,
        public string $senderMaskOrServiceNickname,
        public ?MessageDelivery $delivery,
        public string $message,
        public DateTimeImmutable $occurredAt,
    ) {}
}
