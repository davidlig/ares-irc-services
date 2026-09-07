<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

final readonly class ChannelSetting
{
    public function __construct(
        public ModeName $mode,
        public ?string $parameter = null,
    ) {}
}
