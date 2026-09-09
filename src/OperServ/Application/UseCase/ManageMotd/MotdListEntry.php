<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageMotd;

use App\OperServ\Application\Model\MessageDelivery;
use DateTimeImmutable;

final readonly class MotdListEntry
{
    public function __construct(
        public int $id,
        public string $text,
        public string $botNickname,
        public MessageDelivery $delivery,
        public bool $enabled,
        public bool $expired,
        public ?DateTimeImmutable $expiresAt,
        public int $shownCount,
    ) {}
}
