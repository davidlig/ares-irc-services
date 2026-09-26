<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\List;

use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;

final readonly class ListedNickAccount
{
    public function __construct(
        public string $nickname,
        public ?DateTimeImmutable $registeredAt,
        public ?DateTimeImmutable $lastSeenAt,
        public ?string $lastConnectIp,
        public NickStatus $status,
    ) {}
}
