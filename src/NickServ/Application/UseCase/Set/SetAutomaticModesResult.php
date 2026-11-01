<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Set;

final readonly class SetAutomaticModesResult
{
    public function __construct(public SetAutomaticModesOutcome $outcome, public string $modes = '') {}
}
