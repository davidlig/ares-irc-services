<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

final readonly class ModeChange
{
    public function __construct(
        public ModeName $mode,
        public ModeChangeAction $action,
        public ?string $parameter = null,
    ) {}
}
