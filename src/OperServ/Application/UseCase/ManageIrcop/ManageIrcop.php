<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageIrcop;

use DateTimeImmutable;

final readonly class ManageIrcop
{
    public function __construct(public IrcopAction $action, public string $actorNickname, public ?int $actorAccountId, public string $nickname = '', public string $roleName = '', public ?DateTimeImmutable $occurredAt = null) {}
}
