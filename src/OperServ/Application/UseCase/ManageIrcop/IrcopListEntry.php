<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageIrcop;

use DateTimeImmutable;

final readonly class IrcopListEntry
{
    public function __construct(public string $nickname, public string $role, public DateTimeImmutable $addedAt) {}
}
