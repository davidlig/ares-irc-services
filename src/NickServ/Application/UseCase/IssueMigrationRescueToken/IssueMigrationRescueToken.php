<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\IssueMigrationRescueToken;

use DateTimeImmutable;

final readonly class IssueMigrationRescueToken
{
    public function __construct(public int $accountId, public string $senderUid, public DateTimeImmutable $occurredAt) {}
}
