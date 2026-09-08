<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageIrcop;

final readonly class ManageIrcopResult
{
    /** @param list<IrcopListEntry> $entries */
    public function __construct(public IrcopOutcome $outcome, public ?string $nickname = null, public ?string $role = null, public ?string $oldRole = null, public array $entries = []) {}
}
