<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\SyncNetworkIdentification;

use App\NickServ\Application\Model\NetworkUser;
use DateTimeImmutable;

final readonly class SyncNetworkIdentification
{
    public function __construct(
        public NetworkUser $user,
        public DateTimeImmutable $occurredAt,
    ) {}
}
